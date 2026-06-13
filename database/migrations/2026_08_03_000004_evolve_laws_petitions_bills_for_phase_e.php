<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-CHALLENGE E-4 (PHASE_E_DESIGN_challenge_law §A) — tiny, surgical evolves:
 *
 *  - `laws` — the reserved origin/status CHECKs already carry 'judicial_remedy'
 *    and 'struck' in the live DB (verified at WI-E1); NO schema change.
 *  - `petitions` — attach the review_case_id FK (was a forward ref now that
 *    `cases` exists) + add review_outcome to record the F-JDG-008 disposition
 *    distinctly from the review_stub boolean.
 *  - `emergency_powers` — attach the judicial_review_case_id FK (forward ref).
 *  - `bills` — add targets_challenge_id (a remedial Path-1 bill is tagged to
 *    the challenge it answers).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── petitions ────────────────────────────────────────────────────────
        Schema::table('petitions', function (Blueprint $table) {
            $table->string('review_outcome', 16)->nullable();
            $table->foreign('review_case_id')->references('id')->on('cases')->nullOnDelete();
        });

        DB::statement(
            'ALTER TABLE petitions ADD CONSTRAINT petitions_review_outcome_check '.
            "CHECK (review_outcome IS NULL OR review_outcome IN ('cleared', 'struck'))"
        );

        // ── emergency_powers ─────────────────────────────────────────────────
        Schema::table('emergency_powers', function (Blueprint $table) {
            $table->foreign('judicial_review_case_id')->references('id')->on('cases')->nullOnDelete();
        });

        // ── bills ────────────────────────────────────────────────────────────
        Schema::table('bills', function (Blueprint $table) {
            $table->uuid('targets_challenge_id')->nullable();
            $table->foreign('targets_challenge_id')->references('id')->on('constitutional_challenges')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropForeign(['targets_challenge_id']);
            $table->dropColumn('targets_challenge_id');
        });

        Schema::table('emergency_powers', function (Blueprint $table) {
            $table->dropForeign(['judicial_review_case_id']);
        });

        DB::statement('ALTER TABLE petitions DROP CONSTRAINT IF EXISTS petitions_review_outcome_check');
        Schema::table('petitions', function (Blueprint $table) {
            $table->dropForeign(['review_case_id']);
            $table->dropColumn('review_outcome');
        });
    }
};
