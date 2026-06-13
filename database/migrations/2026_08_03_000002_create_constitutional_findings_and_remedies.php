<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-CHALLENGE E-2 (PHASE_E_DESIGN_challenge_law §A) — the finding (F-JDG-004,
 * §5.2 first half), the remedy recommendation (F-JDG-005, §5.2 second half +
 * the §5.3/§5.4 window-setting), and the reserved multi-law fan-out join.
 *
 * The judge SETS both windows (remedy_timeframe_days / veto_window_days); the
 * constitution caps NEITHER (§5.3 "reasonable timeframe", §5.4 "a set ...
 * window"). The CHECK floors both at > 0 (a zero-day window is incoherent);
 * there is NO ceiling in the engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── F-JDG-004 — the finding ──────────────────────────────────────────
        Schema::create('constitutional_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('challenge_id');
            $table->foreign('challenge_id')->references('id')->on('constitutional_challenges')->cascadeOnDelete();

            $table->uuid('judiciary_id');
            $table->foreign('judiciary_id')->references('id')->on('judiciaries')->restrictOnDelete();

            $table->uuid('case_id')->nullable();
            $table->foreign('case_id')->references('id')->on('cases')->nullOnDelete();

            // Art. IV §4 "Constitutional Questions of significant importance are
            // heard by the entire court" — true when the panel was the whole court.
            $table->boolean('full_court')->default(false);

            // F-JDG-004 always records the determination; false ⇒ dismissed.
            $table->boolean('finds_contradiction');

            $table->string('contradiction_against', 20);
            $table->uuid('superior_authority_law_id')->nullable();
            $table->foreign('superior_authority_law_id')->references('id')->on('laws')->nullOnDelete();
            $table->string('constitutional_citation', 64)->nullable();

            // "what laws are in error" — §5.2 (the single-law spine; the join
            // table reserves the multi-law fan-out).
            $table->uuid('offending_law_id');
            $table->foreign('offending_law_id')->references('id')->on('laws')->restrictOnDelete();
            $table->smallInteger('offending_version_no');

            $table->text('opinion_text');

            // The seated judges who concurred (audit completeness, F-SPK-005 posture).
            $table->jsonb('panel_snapshot')->default('[]');

            $table->uuid('record_id')->nullable();
            $table->timestampTz('issued_at');

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE constitutional_findings ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            'ALTER TABLE constitutional_findings ADD CONSTRAINT constitutional_findings_against_check '.
            "CHECK (contradiction_against IN ('constitution', 'other_law'))"
        );
        // One finding per challenge (partial unique — honours soft deletes).
        DB::statement(
            'CREATE UNIQUE INDEX constitutional_findings_challenge_unique '.
            'ON constitutional_findings (challenge_id) WHERE deleted_at IS NULL'
        );

        // ── the reserved multi-law fan-out join (built now, written only for
        // multi-law findings; the single-law path reads findings.offending_law_id) ──
        Schema::create('finding_offending_laws', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('finding_id');
            $table->foreign('finding_id')->references('id')->on('constitutional_findings')->cascadeOnDelete();

            $table->uuid('law_id');
            $table->foreign('law_id')->references('id')->on('laws')->restrictOnDelete();
            $table->smallInteger('version_no');

            // Each offending law gets its own recommended remedy + own window
            // in the multi-law case (the reserved clock-multiplicity fork).
            $table->uuid('remedy_recommendation_id')->nullable();

            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE finding_offending_laws ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            'CREATE UNIQUE INDEX finding_offending_laws_unique '.
            'ON finding_offending_laws (finding_id, law_id)'
        );

        // ── F-JDG-005 — the remedy recommendation + window setting ───────────
        Schema::create('remedy_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('finding_id');
            $table->foreign('finding_id')->references('id')->on('constitutional_findings')->cascadeOnDelete();

            $table->uuid('challenge_id');
            $table->foreign('challenge_id')->references('id')->on('constitutional_challenges')->cascadeOnDelete();

            $table->uuid('judiciary_id');
            $table->foreign('judiciary_id')->references('id')->on('judiciaries')->restrictOnDelete();

            // §5.3 "modifies or removes" — the recommended disposition.
            $table->string('remedy_kind', 16);

            // The proposed replacement text (for modify); the text F-JDG-006
            // applies directly if the windows expire (§5.5). NULL ⇒ remove.
            $table->text('recommended_text')->nullable();
            $table->text('rationale_text');

            // §5.3 — the judge-set CLK-12 value (> 0, no ceiling).
            $table->smallInteger('remedy_timeframe_days');
            // §5.4 — the judge-set CLK-11 value (> 0, no ceiling).
            $table->smallInteger('veto_window_days');

            $table->timestampTz('remedy_due_at');   // issued_at + remedy_timeframe_days (CLK-12 fires_at)
            $table->timestampTz('veto_closes_at');   // issued_at + veto_window_days (CLK-11 fires_at)

            $table->uuid('clk11_timer_id')->nullable();
            $table->uuid('clk12_timer_id')->nullable();

            $table->uuid('record_id')->nullable();
            $table->timestampTz('issued_at');

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE remedy_recommendations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            'ALTER TABLE remedy_recommendations ADD CONSTRAINT remedy_recommendations_kind_check '.
            "CHECK (remedy_kind IN ('modify', 'remove'))"
        );
        // §5.3/§5.4 — windows floor at > 0, NO ceiling (the constitution states none).
        DB::statement(
            'ALTER TABLE remedy_recommendations ADD CONSTRAINT remedy_recommendations_timeframe_check '.
            'CHECK (remedy_timeframe_days > 0)'
        );
        DB::statement(
            'ALTER TABLE remedy_recommendations ADD CONSTRAINT remedy_recommendations_veto_check '.
            'CHECK (veto_window_days > 0)'
        );
        // One recommendation per challenge (partial unique).
        DB::statement(
            'CREATE UNIQUE INDEX remedy_recommendations_challenge_unique '.
            'ON remedy_recommendations (challenge_id) WHERE deleted_at IS NULL'
        );

        // The deferred FKs from constitutional_challenges (the tables exist now).
        Schema::table('constitutional_challenges', function (Blueprint $table) {
            $table->foreign('finding_id')->references('id')->on('constitutional_findings')->nullOnDelete();
            $table->foreign('remedy_id')->references('id')->on('remedy_recommendations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('constitutional_challenges', function (Blueprint $table) {
            $table->dropForeign(['finding_id']);
            $table->dropForeign(['remedy_id']);
        });

        Schema::dropIfExists('remedy_recommendations');
        Schema::dropIfExists('finding_offending_laws');
        Schema::dropIfExists('constitutional_findings');
    }
};
