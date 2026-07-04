<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5e — pin the drawn-district label uniqueness to LIVE rows only, and
 * heal the ghosts that already slipped through.
 *
 * The operator hit a 500 (SQLSTATE 23505 on district_subdivisions_map_label_unique,
 * "Serravalle — drawn district 1") committing an autoseed plan after clearing the
 * previous set. Root cause: deleteDistrict() soft-deleted the legislature_districts
 * row and hard-deleted its memberships but never touched the district_subdivisions
 * row — the subdivision stayed LIVE (deleted_at NULL) with its label, so the next
 * filing's label collided with the ghost. Two fixes ride together:
 *
 *  1. deleteDistrict() now retires linked subdivisions (code fix, same commit);
 *  2. this migration (a) recreates the unique index explicitly as a partial
 *     index over live rows — the original create migration already carried the
 *     WHERE clause, but recreating it here guarantees every deployed box holds
 *     the partial posture regardless of which revision created its table — and
 *     (b) soft-deletes the ORPHANED live subdivision rows those earlier deletes
 *     left behind (live subdivision with no live district reachable through a
 *     membership row), so already-broken boxes heal on migrate.
 *
 * ADDITIVE only: no column changes; district_subdivisions is not mesh-synced
 * and not constitutionally protected. The handler's label numbering is also
 * collision-proof on its own now (belt and braces) — a 23505 here must be
 * unreachable.
 */
return new class extends Migration
{
    public function up(): void
    {
        // (a) Recreate as a partial unique index over live rows, keeping the
        // original name so every code/comment reference stays true.
        DB::statement('DROP INDEX IF EXISTS district_subdivisions_map_label_unique');
        DB::statement(
            'CREATE UNIQUE INDEX district_subdivisions_map_label_unique '
          .'ON district_subdivisions (map_id, label) WHERE deleted_at IS NULL'
        );

        // (b) Heal ghosts: the F-ELB-008 handler always writes the subdivision
        // and its membership row in one transaction, so a LIVE subdivision with
        // no live district behind any membership can only be the residue of a
        // pre-fix delete. Retire it the way deleteDistrict() now does.
        DB::statement(
            'UPDATE district_subdivisions ds
                SET deleted_at = now(), updated_at = now()
              WHERE ds.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1
                      FROM legislature_district_jurisdictions ldj
                      JOIN legislature_districts ld
                        ON ld.id = ldj.district_id AND ld.deleted_at IS NULL
                     WHERE ldj.subdivision_id = ds.id
                )'
        );
    }

    public function down(): void
    {
        // The partial index IS the original posture (the create migration
        // carried the WHERE clause from birth) — recreate it identically.
        // The orphan heal is a data repair and is not reversed.
        DB::statement('DROP INDEX IF EXISTS district_subdivisions_map_label_unique');
        DB::statement(
            'CREATE UNIQUE INDEX district_subdivisions_map_label_unique '
          .'ON district_subdivisions (map_id, label) WHERE deleted_at IS NULL'
        );
    }
};
