<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WI-5 — Residency Claim state machine (ESM-02) + identity-table evolutions.
 *
 * RECONCILIATION NOTE (DESIGN_schema_engine.md §A.1 vs DESIGN_roadmap_phaseA.md
 * WI-5): the schema design names a new `jurisdiction_associations` table; the
 * roadmap instead REUSES `residency_confirmations` as the association rows
 * (one row per enclosing jurisdiction level, rights booleans already encoding
 * Art. I). This migration follows the roadmap: `residency_confirmations`
 * gains `claim_id` (provenance) + `depth` (0 = declared boundary, +1 per
 * ancestor toward root), and its full UNIQUE (user_id, jurisdiction_id)
 * becomes a PARTIAL unique scoped to active rows so deactivated history can
 * accumulate (the associations design keeps history via ended rows, not
 * soft deletes — residency_confirmations deliberately has no deleted_at;
 * `is_active` + `deactivated_at` are its history mechanism).
 *
 * residency_claims — the 7-state Residency Claim machine:
 *   declared → ping_monitoring → threshold_met → verified → active
 *                                                  └→ superseded / lapsed
 * One OPEN claim per user (partial unique below): a claim in any state
 * except superseded/lapsed blocks a second declaration. Relocation
 * (new claim while one is ACTIVE, zero rights gap — WF-CIV-03) is Phase C;
 * boundary correction during monitoring supersedes the open claim.
 *
 * location_pings — gains `claim_id` linkage plus the CLK-05 evaluator
 * columns (`is_qualifying`, `evaluated_at` — consumed by WI-6's
 * EvaluateResidencyThresholdsJob), and the source CHECK from the ESM
 * (`manual`/`simulated` cover Phase A dev pinging). PRIVACY: raw pings for
 * a claim are DELETED on verification (code rule, ResidencyService::verify)
 * — coordinates never outlive verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── residency_claims ────────────────────────────────────────────────
        Schema::create('residency_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // The SMALLEST declared boundary (the resident picks the deepest
            // jurisdiction containing their home); ancestors derive at verify.
            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            $table->string('status', 24)->default('declared');

            $table->timestampTz('declared_at');
            // F-IND-003 requires explicit ping consent — rejected without
            // (verification cannot run otherwise). NOT NULL by design.
            $table->timestampTz('ping_consent_at');

            // Denormalized count of DISTINCT days with a qualifying ping
            // inside the declared boundary; recomputed on each ping and by
            // the CLK-05 evaluator (WI-6).
            $table->smallInteger('qualifying_days')->default(0);

            // Snapshot of the resolved residency_confirmation_days at the
            // moment the threshold was met — later setting amendments do not
            // retroactively re-gate a verification already earned.
            $table->smallInteger('threshold_days_at_verification')->nullable();

            $table->timestampTz('threshold_met_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampTz('lapsed_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'status']);
            $table->index('jurisdiction_id');
        });

        DB::statement('ALTER TABLE residency_claims ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            "ALTER TABLE residency_claims ADD CONSTRAINT residency_claims_status_check " .
            "CHECK (status IN ('declared', 'ping_monitoring', 'threshold_met', 'verified', 'active', 'superseded', 'lapsed'))"
        );

        // One OPEN claim per user (any state except superseded/lapsed).
        DB::statement(
            "CREATE UNIQUE INDEX residency_claims_one_open_per_user " .
            "ON residency_claims (user_id) " .
            "WHERE status NOT IN ('superseded', 'lapsed') AND deleted_at IS NULL"
        );

        // ── location_pings evolutions ───────────────────────────────────────
        Schema::table('location_pings', function (Blueprint $table) {
            $table->uuid('claim_id')->nullable();
            $table->foreign('claim_id')->references('id')->on('residency_claims')->nullOnDelete();

            // CLK-05 evaluator columns (WI-6): was this ping's day inside the
            // claim boundary, and when was that evaluated.
            $table->boolean('is_qualifying')->nullable();
            $table->timestampTz('evaluated_at')->nullable();

            $table->index('claim_id');
        });

        // Phase A pings arrive via the web UI / dev simulator, not mobile.
        DB::statement("ALTER TABLE location_pings ALTER COLUMN source SET DEFAULT 'manual'");
        DB::statement(
            "ALTER TABLE location_pings ADD CONSTRAINT location_pings_source_check " .
            "CHECK (source IN ('mobile', 'web', 'manual', 'simulated'))"
        );

        // ── residency_confirmations evolutions (association rows) ───────────
        Schema::table('residency_confirmations', function (Blueprint $table) {
            // Provenance: which claim's verification created this association.
            // nullOnDelete — associations outlive claim cleanup.
            $table->uuid('claim_id')->nullable();
            $table->foreign('claim_id')->references('id')->on('residency_claims')->nullOnDelete();

            // 0 = declared jurisdiction, +1 per ancestor toward root
            // (dual-footprint twins share depth 0; merged chains keep the
            // minimum depth). Null on legacy rows.
            $table->smallInteger('depth')->nullable();

            // Replaced below by a partial unique over active rows so history
            // (deactivated associations) can accumulate per jurisdiction.
            $table->dropUnique(['user_id', 'jurisdiction_id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX residency_confirmations_user_jur_active_unique ' .
            'ON residency_confirmations (user_id, jurisdiction_id) WHERE is_active'
        );

        // Population-counting helper (CLK-06 reads this in WI-6).
        DB::statement(
            'CREATE INDEX residency_confirmations_jurisdiction_active_idx ' .
            'ON residency_confirmations (jurisdiction_id) WHERE is_active'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS residency_confirmations_jurisdiction_active_idx');
        DB::statement('DROP INDEX IF EXISTS residency_confirmations_user_jur_active_unique');

        Schema::table('residency_confirmations', function (Blueprint $table) {
            $table->dropForeign(['claim_id']);
            $table->dropColumn(['claim_id', 'depth']);
            $table->unique(['user_id', 'jurisdiction_id']);
        });

        DB::statement('ALTER TABLE location_pings DROP CONSTRAINT IF EXISTS location_pings_source_check');
        DB::statement("ALTER TABLE location_pings ALTER COLUMN source SET DEFAULT 'mobile'");

        Schema::table('location_pings', function (Blueprint $table) {
            $table->dropForeign(['claim_id']);
            $table->dropColumn(['claim_id', 'is_qualifying', 'evaluated_at']);
        });

        Schema::dropIfExists('residency_claims');
    }
};
