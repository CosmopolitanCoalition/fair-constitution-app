<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-O3 (PHASE_D_DESIGN_organizations §A) — minimal-viable contracts.
 *
 * `labor_recurring` feeds org_workers (an active org_workers row requires
 * a co-signed labor_recurring contract). The status CHECK is the
 * belt-and-suspenders behind the engine rule: co-sign required — the
 * engine (OrgContractService, the only writer) rejects single-sided
 * activation; each signature is its own audit entry.
 *
 * Full contracts engine (obligations, payment schedules, breach) is the
 * owner post-it "D. Noted for data-structure phase" — deferred (§E.4.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The org party.
            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            // 'users' | 'organizations'.
            $table->string('counterparty_type', 16);
            $table->uuid('counterparty_id');

            $table->string('kind', 16);

            $table->text('terms');

            $table->uuid('signed_by_org_user_id')->nullable();
            $table->foreign('signed_by_org_user_id')->references('id')->on('users')->nullOnDelete();
            $table->timestampTz('signed_by_org_at')->nullable();

            // Counterparty user signs for self; counterparty org via agent.
            $table->timestampTz('signed_by_counterparty_at')->nullable();

            $table->string('status', 8)->default('draft');

            $table->timestampTz('effective_at')->nullable();
            $table->timestampTz('ended_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'status']);
            $table->index(['counterparty_type', 'counterparty_id']);
        });

        DB::statement('ALTER TABLE org_contracts ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE org_contracts ADD CONSTRAINT org_contracts_counterparty_type_check CHECK (counterparty_type IN ('users', 'organizations'))");
        DB::statement("ALTER TABLE org_contracts ADD CONSTRAINT org_contracts_kind_check CHECK (kind IN ('labor_recurring', 'labor_single', 'commercial', 'other'))");
        DB::statement("ALTER TABLE org_contracts ADD CONSTRAINT org_contracts_status_check CHECK (status IN ('draft', 'offered', 'active', 'ended', 'voided'))");
        // Co-sign gate, DB layer.
        DB::statement(
            "ALTER TABLE org_contracts ADD CONSTRAINT org_contracts_cosign_check CHECK " .
            "(status <> 'active' OR (signed_by_org_at IS NOT NULL AND signed_by_counterparty_at IS NOT NULL))"
        );

        // Forward ref declared in D-O2: org_workers.contract_id.
        DB::statement(
            'ALTER TABLE org_workers ADD CONSTRAINT org_workers_contract_id_foreign ' .
            'FOREIGN KEY (contract_id) REFERENCES org_contracts(id) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE org_workers DROP CONSTRAINT IF EXISTS org_workers_contract_id_foreign');
        Schema::dropIfExists('org_contracts');
    }
};
