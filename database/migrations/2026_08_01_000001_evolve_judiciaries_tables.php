<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-1 (PHASE_E_DESIGN_judiciary §A) — evolve the Phase 0 judiciaries stub
 * into the ESM-18 machine. ADDITIVE on a live dev DB: every existing row
 * is `forming`, so the new status CHECK back-fills as a no-op, exactly the
 * way D-1 evolved the executives stub.
 *
 *  - judiciaries: the full ESM-18 status CHECK (the stub had only
 *    forming|active|dissolved); the creation/conversion provenance
 *    columns; `nomination_mode` (Art. IV §2 — constituent | committee,
 *    DERIVED at creation); `judge_count` (the seat-pool size, ≥ min_judges).
 *    ONE row per jurisdiction is preserved — conversion EVOLVES the same
 *    row (type flips appointed→elected; the appointed era's seats close,
 *    never delete). The DEFAULT type stays `appointed` (Art. IV §1).
 *  - elections.judiciary_id — the office a `judicial`-kind election fills
 *    (the executive_id precedent, D-1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('judiciaries', function (Blueprint $table) {
            // The F-LEG-017 Judiciary Creation Act (laws.kind = 'charter').
            $table->uuid('creation_law_id')->nullable();
            $table->foreign('creation_law_id')->references('id')->on('laws')->nullOnDelete();

            // Art. IV §2 path that seated the bench — DERIVED at creation,
            // never an input. NULL until creation adopts.
            $table->string('nomination_mode', 20)->nullable();

            // The F-LEG-018 dual-supermajority process (same shape as
            // executives.conversion_process_id).
            $table->uuid('conversion_process_id')->nullable();
            $table->foreign('conversion_process_id')->references('id')->on('multi_jurisdiction_votes')->nullOnDelete();

            $table->uuid('conversion_law_id')->nullable();
            $table->foreign('conversion_law_id')->references('id')->on('laws')->nullOnDelete();

            $table->timestampTz('converted_at')->nullable();

            // The bench size fixed by the creation act — the seat-pool size,
            // distinct from min_judges (the per-RACE floor for elected
            // courts). ≥ min_judges (Art. IV §1).
            $table->smallInteger('judge_count')->nullable();

            // The chartering chamber (lockstep anchor for elected judges).
            $table->uuid('source_legislature_id')->nullable();
            $table->foreign('source_legislature_id')->references('id')->on('legislatures')->nullOnDelete();
        });

        // RECUT the stub's 3-value status enum to the full ESM-18 (mirrors
        // executives_status_check exactly). Back-fill no-op (all `forming`).
        DB::statement('ALTER TABLE judiciaries DROP CONSTRAINT IF EXISTS judiciaries_status_check');
        DB::statement(
            'ALTER TABLE judiciaries ADD CONSTRAINT judiciaries_status_check CHECK (status IN ('.
            "'forming', 'creating', 'appointed', 'conversion_voted', 'elected', 'dissolved', 'reverted'))"
        );

        // nomination_mode is constituent | committee (or NULL pre-creation).
        DB::statement(
            'ALTER TABLE judiciaries ADD CONSTRAINT judiciaries_nomination_mode_check '.
            "CHECK (nomination_mode IS NULL OR nomination_mode IN ('constituent', 'committee'))"
        );

        // The seat-pool floor (Art. IV §1): a chartered bench is never
        // smaller than min_judges (default 5).
        DB::statement(
            'ALTER TABLE judiciaries ADD CONSTRAINT judiciaries_judge_count_check '.
            'CHECK (judge_count IS NULL OR judge_count >= min_judges)'
        );

        // elections.judiciary_id — the judiciary a `judicial`-kind election
        // fills (the D-1 executive_id precedent; 'judicial' is already in
        // elections_kind_check from the B-3 stub).
        Schema::table('elections', function (Blueprint $table) {
            $table->uuid('judiciary_id')->nullable();
            $table->foreign('judiciary_id')->references('id')->on('judiciaries')->nullOnDelete();

            $table->index(['judiciary_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('elections', function (Blueprint $table) {
            $table->dropForeign(['judiciary_id']);
            $table->dropIndex(['judiciary_id', 'status']);
            $table->dropColumn('judiciary_id');
        });

        DB::statement('ALTER TABLE judiciaries DROP CONSTRAINT IF EXISTS judiciaries_judge_count_check');
        DB::statement('ALTER TABLE judiciaries DROP CONSTRAINT IF EXISTS judiciaries_nomination_mode_check');

        DB::statement('ALTER TABLE judiciaries DROP CONSTRAINT IF EXISTS judiciaries_status_check');
        DB::statement(
            'ALTER TABLE judiciaries ADD CONSTRAINT judiciaries_status_check '.
            "CHECK (status IN ('forming', 'active', 'dissolved'))"
        );

        Schema::table('judiciaries', function (Blueprint $table) {
            $table->dropForeign(['creation_law_id']);
            $table->dropForeign(['conversion_process_id']);
            $table->dropForeign(['conversion_law_id']);
            $table->dropForeign(['source_legislature_id']);
            $table->dropColumn([
                'creation_law_id', 'nomination_mode', 'conversion_process_id',
                'conversion_law_id', 'converted_at', 'judge_count', 'source_legislature_id',
            ]);
        });
    }
};
