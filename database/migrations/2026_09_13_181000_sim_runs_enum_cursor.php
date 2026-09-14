<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE SIM ENUMERATION CURSOR (G2 — repair simulation resume enumeration).
 *
 * sim:start enumerated its cohort worklist by OFFSET and looped on rows
 * INSERTED, so a resume whose first page was already enrolled saw zero
 * inserts and exited before it reached the later, unenrolled cohorts. The
 * fix is a keyset walk that loops on rows SCANNED and persists a durable
 * high-water mark per committed chunk — the same resumable shape the Step 4
 * sibling uses (provision_runs.ledger_cursor).
 *
 * The sim ordering key is compound — COALESCE(population,0) DESC, id — so
 * the cursor is a jsonb object, not a single uuid:
 *   {"key": <population>, "id": "<uuid>", "position_max": <int>, "scanned_total": <int>}
 *
 * Additive, REAL-dated after 2026_07_25 (sim_runs). jsonb on Postgres;
 * Laravel's jsonb blueprint emits TEXT on sqlite, so the DB-free fixture
 * carries it as JSON text. The baseline dump (pgsql-schema.sql) is not
 * touched: a fresh install loads the dump then applies this on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sim_runs', function (Blueprint $t) {
            if (! Schema::hasColumn('sim_runs', 'enum_cursor')) {
                $t->jsonb('enum_cursor')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sim_runs', function (Blueprint $t) {
            if (Schema::hasColumn('sim_runs', 'enum_cursor')) {
                $t->dropColumn('enum_cursor');
            }
        });
    }
};
