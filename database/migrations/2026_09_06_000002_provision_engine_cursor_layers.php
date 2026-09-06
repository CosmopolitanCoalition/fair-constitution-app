<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE STEP 4 ENGINE, dry-run fixes (2026-09-06):
 *
 *  1. RESUMABLE SEEDING. provision_runs.ledger_cursor is the seed high-water
 *     mark over legislature id. The materializer resumes from it instead of
 *     restarting at zero every pump tick (the defect that stalled the first
 *     run at 200,000 of 940,325 rows with no lane ever dispatched).
 *  2. HONEST WORLD COUNTS. provision_runs.world_baseline captures the
 *     institution counts at run start so the page shows this run's deltas, not
 *     the pre-existing stub rows.
 *  3. THE LAYER BLOCK. provision_ledger.adm_level lets the build proceed
 *     top-down by layer (planet, then countries, down to neighborhoods) like
 *     the districting block order, two-ended by cost within a layer.
 *
 * Additive, REAL-dated, guarded so a fresh install (empty provision_ledger)
 * skips the backfill. The backfill is keyset-chunked (THE ETL RULE).
 */
return new class extends Migration
{
    private const CHUNK = 25000;

    public function up(): void
    {
        Schema::table('provision_runs', function (Blueprint $t) {
            if (! Schema::hasColumn('provision_runs', 'ledger_cursor')) {
                $t->uuid('ledger_cursor')->nullable();
            }
            if (! Schema::hasColumn('provision_runs', 'world_baseline')) {
                $t->jsonb('world_baseline')->nullable();
            }
        });

        Schema::table('provision_ledger', function (Blueprint $t) {
            if (! Schema::hasColumn('provision_ledger', 'adm_level')) {
                $t->smallInteger('adm_level')->nullable();
            }
        });

        DB::statement('CREATE INDEX IF NOT EXISTS pl_layer_claim_idx
                       ON provision_ledger (status, stage, adm_level, est_cost, legislature_id)');

        // Backfill adm_level for rows seeded before the column existed, keyset
        // by legislature id (bounded). A fresh install has no rows: a no-op.
        $after = '00000000-0000-0000-0000-000000000000';
        while (true) {
            $row = DB::selectOne('
                WITH page AS (
                    SELECT pl.legislature_id
                      FROM provision_ledger pl
                     WHERE pl.legislature_id > ?::uuid AND pl.adm_level IS NULL
                     ORDER BY pl.legislature_id
                     LIMIT ?
                ),
                upd AS (
                    UPDATE provision_ledger pl
                       SET adm_level = j.adm_level
                      FROM page p, jurisdictions j
                     WHERE pl.legislature_id = p.legislature_id AND j.id = pl.jurisdiction_id
                    RETURNING pl.legislature_id
                )
                SELECT (SELECT count(*) FROM page) AS scanned,
                       (SELECT legislature_id FROM page ORDER BY legislature_id DESC LIMIT 1) AS last_id
            ', [$after, self::CHUNK]);
            $scanned = (int) ($row->scanned ?? 0);
            if ($scanned === 0) {
                break;
            }
            $after = (string) $row->last_id;
            if ($scanned < self::CHUNK) {
                break;
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pl_layer_claim_idx');
        Schema::table('provision_ledger', function (Blueprint $t) {
            if (Schema::hasColumn('provision_ledger', 'adm_level')) {
                $t->dropColumn('adm_level');
            }
        });
        Schema::table('provision_runs', function (Blueprint $t) {
            foreach (['ledger_cursor', 'world_baseline'] as $c) {
                if (Schema::hasColumn('provision_runs', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
