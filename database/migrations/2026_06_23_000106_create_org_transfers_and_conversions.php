<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-O6 (PHASE_D_DESIGN_organizations §A) — ownership transfers (mutual
 * consent, WF-ORG-06) and public↔private conversions (WF-ORG-07/08/09).
 *
 * Transfers: `consented` requires BOTH consents — the engine rejects
 * anything less. The ONLY ownership path overriding owner consent is
 * monopoly acquisition, which is a CONVERSION, never a transfer.
 *
 * Conversions: both directions are legislature-only (CGCs are never
 * self-converted) — `authorizing_law_id` is engine-REQUIRED before status
 * may pass `voted`. The fair-market floor is recorded BEFORE the
 * compensation step; `compensation < fair_market_floor` is engine-blocked
 * (hardened — Art. III §5) with the DB CHECK as the belt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The entity whose ownership moves.
            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            // 'users' | 'organizations'.
            $table->string('to_party_type', 16);
            $table->uuid('to_party_id');

            $table->text('terms')->nullable();

            $table->timestampTz('consent_from_at')->nullable();
            $table->uuid('consent_from_user_id')->nullable();
            $table->timestampTz('consent_to_at')->nullable();
            $table->uuid('consent_to_user_id')->nullable();

            $table->string('status', 10)->default('proposed');

            // Completion = OrgOwnershipService closes/opens stake rows in
            // one transaction.
            $table->timestampTz('completed_at')->nullable();

            // Phase F full-faith-and-credit stub (WF-JUR-06).
            $table->timestampTz('ffc_synced_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'status']);
        });

        DB::statement('ALTER TABLE org_transfers ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE org_transfers ADD CONSTRAINT org_transfers_to_party_type_check CHECK (to_party_type IN ('users', 'organizations'))");
        DB::statement("ALTER TABLE org_transfers ADD CONSTRAINT org_transfers_status_check CHECK (status IN ('proposed', 'consented', 'completed', 'abandoned'))");
        // Mutual consent, DB layer.
        DB::statement(
            "ALTER TABLE org_transfers ADD CONSTRAINT org_transfers_mutual_consent_check CHECK " .
            "(status NOT IN ('consented', 'completed') OR (consent_from_at IS NOT NULL AND consent_to_at IS NOT NULL))"
        );

        // Forward ref declared in D-O4: stake provenance.
        DB::statement(
            'ALTER TABLE org_ownership_stakes ADD CONSTRAINT org_ownership_stakes_source_transfer_id_foreign ' .
            'FOREIGN KEY (source_transfer_id) REFERENCES org_transfers(id) ON DELETE SET NULL'
        );

        Schema::create('org_conversions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->string('direction', 16);

            // mutual = for-sale entity + F-ORG-006 request; monopoly =
            // F-LEG-026 finding path; cgc_sale = F-LEG-027 sell branch.
            $table->string('via', 24);

            // The legislative act vote (soft refs — chamber_vote_proposals
            // / chamber_votes).
            $table->uuid('proposal_id')->nullable();
            $table->uuid('authorizing_vote_id')->nullable();

            $table->uuid('authorizing_law_id')->nullable();
            $table->foreign('authorizing_law_id')->references('id')->on('laws')->restrictOnDelete();

            // Recorded BEFORE the compensation step; required for
            // private_to_cgc.
            $table->decimal('fair_market_floor', 18, 2)->nullable();
            $table->text('fair_market_basis')->nullable();

            $table->decimal('compensation', 18, 2)->nullable();
            $table->uuid('compensation_record_id')->nullable();

            // Founding-governor offers to the prior board:
            // [{user_id, offered_at, response, appointment_id}].
            $table->jsonb('board_transition')->default('[]');

            $table->string('status', 24)->default('proposed');
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'status']);
        });

        DB::statement('ALTER TABLE org_conversions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE org_conversions ADD CONSTRAINT org_conversions_direction_check CHECK (direction IN ('private_to_cgc', 'cgc_to_private'))");
        DB::statement("ALTER TABLE org_conversions ADD CONSTRAINT org_conversions_via_check CHECK (via IN ('mutual', 'monopoly_acquisition', 'cgc_sale'))");
        DB::statement(
            "ALTER TABLE org_conversions ADD CONSTRAINT org_conversions_status_check CHECK (status IN (" .
            "'proposed', 'voted', 'compensation_pending', 'converting', 'completed', 'abandoned'))"
        );
        // Fair-market floor, DB layer (Art. III §5).
        DB::statement(
            'ALTER TABLE org_conversions ADD CONSTRAINT org_conversions_fair_market_check CHECK ' .
            '(compensation IS NULL OR fair_market_floor IS NULL OR compensation >= fair_market_floor)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('org_conversions');
        DB::statement('ALTER TABLE org_ownership_stakes DROP CONSTRAINT IF EXISTS org_ownership_stakes_source_transfer_id_foreign');
        Schema::dropIfExists('org_transfers');
    }
};
