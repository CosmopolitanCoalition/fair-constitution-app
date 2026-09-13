<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IO-2 appeals workflow (operator ruling 2026-09-13, appeals-workflow-rules = B).
 *
 * The appellate OUTCOME is recorded on the appeal case's opinion row: an appeal
 * is a NEW `cases` row linked by `appeal_of_case_id` (already in the baseline),
 * and the panel that hears it files a F-JDG-003 opinion carrying the ruling.
 * The lawful appellate outcomes (civil: affirm / reverse / remand; criminal:
 * affirm / vacate — Art. II §8, never a re-trial) are NOT `verdicts` outcomes,
 * so they live in a dedicated nullable column on `opinions`. A verdict outcome
 * is unchanged.
 *
 * Also adds the reverse-lookup index the `appeals()` relation wants: the
 * baseline carries only the FK on `cases.appeal_of_case_id` (no index), so an
 * original's appeals list scans without one.
 *
 * Additive, real-dated (≥ 2026-07-05), applied on top of the flattened
 * baseline. NOT run here — the desk applies it. sqlite-safe (tests build their
 * own schema); the CONCURRENTLY / lock_timeout path is pgsql-only.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        // On PostgreSQL, ADD COLUMN takes ACCESS EXCLUSIVE on `opinions`. Guard
        // it with a short lock_timeout BEFORE the column add so it fails fast on
        // contention (a long report query on the live world, autovacuum) instead
        // of queuing behind the lock holder and blocking every new query on the
        // table behind it — the ETL Paradigm box-stall hazard. $withinTransaction
        // is false, so the SET persists for the session and also covers the
        // CONCURRENTLY index below.
        if ($pgsql) {
            DB::statement("SET lock_timeout = '5s'");
        }

        try {
            if (! Schema::hasColumn('opinions', 'appeal_outcome')) {
                Schema::table('opinions', function (Blueprint $t) {
                    // affirm / reverse / remand (civil) · affirm / vacate (criminal).
                    $t->string('appeal_outcome', 16)->nullable();
                });
            }

            if (! $pgsql) {
                return; // sqlite (tests) — the partial index below is pgsql-only.
            }

            $name = 'cases_appeal_of_case_id_idx';

            // Recover a prior failed CONCURRENTLY build (INVALID index) before rebuild.
            $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);

            if ($index !== null && ! $index->indisvalid) {
                DB::statement('DROP INDEX CONCURRENTLY '.$name);
            }

            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name
                .' ON cases (appeal_of_case_id) WHERE appeal_of_case_id IS NOT NULL'
            );
        } finally {
            if ($pgsql) {
                DB::statement('RESET lock_timeout');
            }
        }
    }

    public function down(): void
    {
        // Recorded appellate rulings survive a code rollback; the column stays.
    }
};
