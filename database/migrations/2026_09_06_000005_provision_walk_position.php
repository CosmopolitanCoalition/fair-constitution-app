<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE STAMPED WALK (operator insight 2026-09-06: Step 3 stamps the whole
 * dispatch order ONCE near the geometry precompute, and every subsequent claim
 * is an index pop of that single number — never a re-scan, never a re-sort).
 * Step 4 was sorting all ~889k pending rows on every claim (a 2.77s claim). This
 * gives provision_ledger the same single-column order: walk_position, computed
 * once, so the claim orders by ONE column — topdown pops the low end, bottomup
 * the high end (the same index scanned backward). Stamped here for the live run;
 * fresh runs stamp at seed completion (ProvisionRunControl::stampOrder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provision_ledger', function (Blueprint $t) {
            if (! Schema::hasColumn('provision_ledger', 'walk_position')) {
                $t->integer('walk_position')->nullable();
            }
        });

        // One ordered pass: the SAME order the claim wanted (layer top-down,
        // biggest chamber first within a layer), folded into one integer. The
        // sort happens once here, not once per claim. A fresh install has no
        // rows, so this is a no-op there.
        DB::statement("
            WITH ordered AS (
                SELECT legislature_id,
                       row_number() OVER (ORDER BY adm_level ASC, est_cost DESC, legislature_id ASC) AS wp
                  FROM provision_ledger
                 WHERE status <> 'skipped'
            )
            UPDATE provision_ledger pl
               SET walk_position = o.wp
              FROM ordered o
             WHERE pl.legislature_id = o.legislature_id
        ");

        // The claim index: an index pop of the leading (or trailing) edge among
        // pending rows at a stage. Partial on status = 'pending' so it shrinks
        // as the run completes.
        DB::statement("CREATE INDEX IF NOT EXISTS pl_walk_idx
                       ON provision_ledger (stage, walk_position)
                       WHERE status = 'pending'");

        // The mixed-direction claim index (migration 000004) is superseded by
        // the single-column walk index; drop it so it adds no write overhead.
        DB::statement('DROP INDEX IF EXISTS pl_claim_dir_idx');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pl_walk_idx');
        Schema::table('provision_ledger', function (Blueprint $t) {
            if (Schema::hasColumn('provision_ledger', 'walk_position')) {
                $t->dropColumn('walk_position');
            }
        });
    }
};
