<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-O4 (PHASE_D_DESIGN_organizations §A) — the share system (owner
 * ruling #12).
 *
 * Stakes determine WHO stands on the owner side and the ECONOMICS
 * (compensation/transfer) — NEVER vote weight (§C.1 decision: owner-track
 * board elections are one-member-one-vote within the class; q-ledger
 * candidate recorded).
 *
 * CGC posture: exactly one open stake row, holder_type='jurisdictions',
 * 100% — the BoG stands where shareholders would.
 *
 * CONVENTION EXCEPTION: no soft deletes — closure via ended_at (current
 * cap table = ended_at IS NULL), history preserved, mirroring
 * jurisdiction_associations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_ownership_stakes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            // 'users' | 'organizations' | 'jurisdictions'.
            $table->string('holder_type', 16);
            $table->uuid('holder_id');

            $table->decimal('units', 20, 6);

            // Denormalized snapshot; recomputed by OrgOwnershipService on
            // any stake write.
            $table->decimal('pct', 7, 4)->nullable();

            $table->string('acquired_via', 12);

            // Provenance (FK in D-O6 — org_transfers is created there).
            $table->uuid('source_transfer_id')->nullable();

            $table->timestampTz('as_of');
            $table->timestampTz('ended_at')->nullable();

            $table->timestampsTz();
            // NO soft deletes — documented exception (ended_at closure).
        });

        DB::statement('ALTER TABLE org_ownership_stakes ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE org_ownership_stakes ADD CONSTRAINT org_ownership_stakes_holder_type_check CHECK (holder_type IN ('users', 'organizations', 'jurisdictions'))");
        DB::statement('ALTER TABLE org_ownership_stakes ADD CONSTRAINT org_ownership_stakes_units_check CHECK (units > 0)');
        DB::statement("ALTER TABLE org_ownership_stakes ADD CONSTRAINT org_ownership_stakes_acquired_via_check CHECK (acquired_via IN ('founding', 'issue', 'transfer', 'conversion'))");
        DB::statement('CREATE INDEX org_ownership_stakes_open_by_org ON org_ownership_stakes (organization_id) WHERE ended_at IS NULL');
        DB::statement('CREATE INDEX org_ownership_stakes_open_by_holder ON org_ownership_stakes (holder_type, holder_id) WHERE ended_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('org_ownership_stakes');
    }
};
