<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 2, lane 13 — the held migration batch, one slot use (desk grant
 * 2026-07-29). Three additive needs folded into one file:
 *
 * 1. JOINT-LEDGER ESCROW (item 3). A joint ledger holds money in an escrow
 *    ECONOMIC ACCOUNT — one set of rails: funding is the existing F-IND-023
 *    transfer, settlement is AccountService escrow→recipient gated on the
 *    approval rule, and the joint tables never touch ledger_entries. The
 *    account plane's two CHECKs learn the new owner kind; the LEDGER's
 *    leg-type constraint is deliberately NOT widened (escrows are ordinary
 *    economic accounts on the ledger).
 *
 * 2. ORG SETTINGS STORAGE (item 6a). Organizational self-governance dials —
 *    first user: the board-election open-nomination window (operator v3.2
 *    item 0d: N days preceding ranking, org-level, role-gated, NEVER a
 *    constitutional value — which is exactly why it cannot ride
 *    constitutional_settings and needs this column).
 *
 * 3. INTERNAL RESTRUCTURING (item 6b). Ownership-structure conversions
 *    WITHIN private hands need N-owner consent state (TransferController's
 *    own comment: "none modelled here"). Consent is per HOLDER, evaluated
 *    against the CURRENT structure's own rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. The escrow owner kind ────────────────────────────────────
        DB::statement('ALTER TABLE economic_accounts DROP CONSTRAINT economic_accounts_kind_check');
        DB::statement(
            "ALTER TABLE economic_accounts ADD CONSTRAINT economic_accounts_kind_check
             CHECK (kind IN ('user', 'organization', 'joint_ledger'))"
        );

        DB::statement('ALTER TABLE economic_account_bindings DROP CONSTRAINT economic_account_bindings_owner_type_check');
        DB::statement(
            "ALTER TABLE economic_account_bindings ADD CONSTRAINT economic_account_bindings_owner_type_check
             CHECK (owner_type IN ('users', 'organizations', 'joint_ledgers'))"
        );

        // ── 2. Org-level settings ───────────────────────────────────────
        Schema::table('organizations', function (Blueprint $table) {
            $table->jsonb('settings')->nullable();
        });

        // ── 3. Restructure proposals + per-holder consent ───────────────
        Schema::create('org_restructures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('from_structure', 24);
            $table->string('to_structure', 24);
            $table->string('status', 12)->default('proposed');
            $table->uuid('proposed_by_user_id')->nullable();
            $table->timestampTz('adopted_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('proposed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_id', 'status']);
        });

        DB::statement(
            "ALTER TABLE org_restructures ADD CONSTRAINT org_restructures_status_check
             CHECK (status IN ('proposed', 'adopted', 'abandoned'))"
        );
        DB::statement(
            "ALTER TABLE org_restructures ADD CONSTRAINT org_restructures_to_structure_check
             CHECK (to_structure IN ('stock', 'partnership', 'equal_partnership', 'member_owned', 'worker_owned', 'nonprofit'))"
        );

        Schema::create('org_restructure_consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('restructure_id');
            $table->string('holder_type', 16);
            $table->uuid('holder_id');
            $table->timestampTz('consented_at');
            $table->timestampsTz();

            $table->foreign('restructure_id')->references('id')->on('org_restructures')->cascadeOnDelete();
            // One consent per holder per proposal — consenting twice is a no-op
            // at the schema, a refusal at the service.
            $table->unique(['restructure_id', 'holder_type', 'holder_id']);
        });

        DB::statement(
            "ALTER TABLE org_restructure_consents ADD CONSTRAINT org_restructure_consents_holder_type_check
             CHECK (holder_type IN ('users', 'organizations'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('org_restructure_consents');
        Schema::dropIfExists('org_restructures');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('settings');
        });

        // Honest limit: restoring the narrow CHECKs requires that no
        // joint-ledger escrow accounts exist — Postgres validates existing
        // rows when a CHECK is added, so a populated joint plane makes this
        // down() fail loudly rather than orphan data. That is correct.
        DB::statement('ALTER TABLE economic_account_bindings DROP CONSTRAINT economic_account_bindings_owner_type_check');
        DB::statement(
            "ALTER TABLE economic_account_bindings ADD CONSTRAINT economic_account_bindings_owner_type_check
             CHECK (owner_type IN ('users', 'organizations'))"
        );

        DB::statement('ALTER TABLE economic_accounts DROP CONSTRAINT economic_accounts_kind_check');
        DB::statement(
            "ALTER TABLE economic_accounts ADD CONSTRAINT economic_accounts_kind_check
             CHECK (kind IN ('user', 'organization'))"
        );
    }
};
