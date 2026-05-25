<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase T.8 Step 5 — Cross-iso orphan worktable + per-iso rewind helper.
 *
 * Why split Step 5 into 5a (discover) + 5b (apply)?
 *   The operator-confirmed rollback semantics are per-ISO: "Rollback ISO
 *   X to Phase 2b" wipes X's correction state, re-runs X's within-iso
 *   correction (Steps 0-3), and re-attributes orphans cascading through
 *   X's levels (Step 5).
 *
 *   The "find an orphan piece's owning iso" computation is GLOBAL — it
 *   ranks the piece against all 232 ISOs' L=1 polygons. You can't
 *   isolate that work per-ISO. But the geometry is deterministic, so
 *   the orphan-to-iso mapping is stable across runs unless an iso's
 *   L=1 changes (Phase 1 rollback territory).
 *
 *   Solution: split Step 5 into two phases.
 *     5a (DISCOVER): scan global tiles once, INSERT each orphan piece +
 *                    its computed owning_iso into a worktable. No
 *                    UPDATEs to jurisdictions. Per-tile checkpoint.
 *                    Cheap to re-run (idempotent — re-inserts same data
 *                    after wipe).
 *     5b (APPLY):    for each ISO with unapplied pieces in the
 *                    worktable, apply the cascade through that ISO's
 *                    levels. Per-piece commit. Per-iso checkpoint.
 *                    Naturally per-ISO chunkable.
 *
 *   Per-iso rollback to Phase 2b now means: call
 *   `cross_iso_orphan_rewind_iso(X)` to undo X's cross-iso credit +
 *   reset its pieces' applied_at; then re-run 5b for X only. 5a stays
 *   as-is (no need to re-discover).
 *
 * Migration ships:
 *   1. `cross_iso_orphan_pieces` worktable storing each discovered
 *      orphan piece with its owning_iso + applied_at + cascade audit.
 *   2. `cross_iso_orphan_rewind_iso(p_iso)` SQL helper that:
 *        - Subtracts `population_cross_iso_correction` from `population`
 *          for every row of p_iso (returning population to its
 *          baseline + overlap + gap corrected value).
 *        - Zeros out `population_cross_iso_correction` for p_iso.
 *        - Resets `applied_at`/`cascaded_levels`/`rows_updated` to NULL
 *          for that iso's pieces in the worktable so 5b re-applies them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Worktable for discovered orphan pieces ─────────────────────
        Schema::create('cross_iso_orphan_pieces', function ($table) {
            $table->bigIncrements('id');
            $table->integer('tile_tx')->index();
            $table->integer('tile_ty')->index();
            $table->string('owning_iso', 3);
            $table->bigInteger('piece_pop');
            $table->double('area_m2')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->jsonb('cascaded_levels')->nullable();
            $table->integer('rows_updated')->nullable();
            $table->timestamp('discovered_at')->default(DB::raw('NOW()'));
        });

        // GEOMETRY column needs raw SQL — Laravel's $table->geometry()
        // is inconsistent across versions on PostGIS. Use explicit ALTER.
        DB::statement(<<<'SQL'
            ALTER TABLE cross_iso_orphan_pieces
            ADD COLUMN piece_geom GEOMETRY(MULTIPOLYGON, 4326) NOT NULL DEFAULT 'MULTIPOLYGON EMPTY';
        SQL);
        // Drop the default once table exists — pieces always get a real geom
        // on insert.
        DB::statement('ALTER TABLE cross_iso_orphan_pieces ALTER COLUMN piece_geom DROP DEFAULT');

        DB::statement(<<<'SQL'
            CREATE INDEX cross_iso_orphan_pieces_geom_gix
                ON cross_iso_orphan_pieces USING GIST (piece_geom);
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX cross_iso_orphan_pieces_iso_applied_idx
                ON cross_iso_orphan_pieces (owning_iso, applied_at);
        SQL);

        // ── Rewind helper ──────────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION cross_iso_orphan_rewind_iso(
                p_iso TEXT
            ) RETURNS TABLE(
                rows_population_reset INT,
                pieces_unapplied      INT
            ) AS $func$
            DECLARE
                v_pop_reset    INT := 0;
                v_pieces_reset INT := 0;
            BEGIN
                -- Subtract this iso's accumulated cross-iso correction from
                -- population, then zero the audit column. Idempotent: re-
                -- running on a freshly-rewound iso is a no-op (correction
                -- column is already 0, so population -= 0).
                WITH updated AS (
                    UPDATE jurisdictions
                       SET population                       = COALESCE(population, 0)
                                                              - COALESCE(population_cross_iso_correction, 0),
                           population_cross_iso_correction  = 0
                     WHERE iso_code   = p_iso
                       AND deleted_at IS NULL
                    RETURNING 1
                )
                SELECT COUNT(*)::INT INTO v_pop_reset FROM updated;

                -- Unmark this iso's pieces in the worktable so 5b picks
                -- them up again on the next apply pass.
                WITH unmarked AS (
                    UPDATE cross_iso_orphan_pieces
                       SET applied_at      = NULL,
                           cascaded_levels = NULL,
                           rows_updated    = NULL
                     WHERE owning_iso = p_iso
                       AND applied_at IS NOT NULL
                    RETURNING 1
                )
                SELECT COUNT(*)::INT INTO v_pieces_reset FROM unmarked;

                RETURN QUERY SELECT v_pop_reset, v_pieces_reset;
            END;
            $func$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS cross_iso_orphan_rewind_iso(TEXT)');
        Schema::dropIfExists('cross_iso_orphan_pieces');
    }
};
