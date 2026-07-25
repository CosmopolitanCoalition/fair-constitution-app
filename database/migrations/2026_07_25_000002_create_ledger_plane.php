<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase L slice L-2 — the ledger spine (docs/plans/economy/ECONOMY_ENGINE_PLAN.md §4.2-4.3).
 *
 * Every value movement in the app becomes a balanced pair of ledger_entries.
 * LedgerService is the only writer; nothing else may debit or credit anything.
 * That single constraint is what makes "Σdebits = Σcredits per currency" a
 * test rather than a hope, and what makes the public ledger audit-complete.
 *
 * ORDER NOTE (design correction): the plan listed the ledger as L-2 and the
 * currency as L-3. That was backwards — a ledger entry must be denominated in
 * something, and a nullable currency FK to be back-filled later is exactly the
 * half-wiring this lane's own audit criticised elsewhere. The two slices are
 * merged here.
 *
 * Art. V §5 — currency production and regulation is RESERVED to the most
 * encompassing jurisdiction. A currency whose issuer is not the root is
 * rejected by CurrencyService and pinned by LedgerIntegrityTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The unit of account ──────────────────────────────────────────
        Schema::create('currencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // The issuing jurisdiction. MUST be the root (Art. V §5).
            $table->uuid('jurisdiction_id');
            $table->string('name', 64);
            $table->string('code', 8);
            $table->string('symbol', 8);
            // Minor units. The stipend dials are whole numbers, but a
            // currency may subdivide (Art. V §5 also reserves the power to
            // "define standards for measurements").
            $table->smallInteger('precision')->default(2);
            $table->string('unit_kind', 24)->default('abstract');
            $table->text('worth_basis')->nullable();
            $table->jsonb('subdivisions')->nullable();
            // The act that defined it (F-LEG-040). NULL for the founding
            // currency written at setup, which predates any legislature.
            $table->uuid('created_by_act_id')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('jurisdiction_id');
        });

        DB::statement(
            "ALTER TABLE currencies ADD CONSTRAINT currencies_unit_kind_check
             CHECK (unit_kind IN ('abstract', 'fiat', 'commodity', 'social_credit', 'external_peg'))"
        );
        DB::statement('ALTER TABLE currencies ADD CONSTRAINT currencies_precision_check CHECK (precision BETWEEN 0 AND 8)');
        DB::statement('CREATE UNIQUE INDEX currencies_code_unique ON currencies (code) WHERE deleted_at IS NULL');

        // ── Public money: a jurisdiction's or department's account ───────
        // Public by construction — a government's money is public business
        // (Art. III §4, Boards of Governors' financial reports).
        Schema::create('treasury_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_type', 24);
            $table->uuid('owner_id');
            $table->uuid('currency_id');
            $table->decimal('balance', 24, 6)->default(0);
            $table->boolean('public')->default(true);
            $table->string('label', 128)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->index(['owner_type', 'owner_id']);
        });

        DB::statement(
            "ALTER TABLE treasury_accounts ADD CONSTRAINT treasury_accounts_owner_type_check
             CHECK (owner_type IN ('jurisdictions', 'departments'))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX treasury_accounts_owner_currency_unique
             ON treasury_accounts (owner_type, owner_id, currency_id) WHERE deleted_at IS NULL'
        );

        // ── The ledger itself ────────────────────────────────────────────
        // Append-only and hash-chained, on the SAME pattern as audit_log:
        // hash = sha256(prev_hash ‖ canonical_json(payload)). Reuses
        // AuditService::chainHash/canonicalJson rather than forking them.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigIncrements('seq')->unique();
            // One balanced posting = one entry_group. Σdebits = Σcredits
            // within a group, per currency.
            $table->uuid('entry_group');
            $table->string('account_type', 24);
            $table->uuid('account_id');
            $table->uuid('currency_id');
            $table->string('direction', 6);
            $table->decimal('amount', 24, 6);
            $table->string('kind', 32);
            $table->string('ref_type', 48)->nullable();
            $table->uuid('ref_id')->nullable();
            $table->char('prev_hash', 64);
            $table->char('hash', 64);
            $table->timestampTz('created_at');

            $table->index('entry_group');
            $table->index(['account_type', 'account_id']);
            $table->index('created_at');
        });

        DB::statement(
            "ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_direction_check
             CHECK (direction IN ('debit', 'credit'))"
        );
        // No zero-value and no negative postings: a movement of nothing is
        // not a movement, and a negative credit is a debit wearing a hat.
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_check CHECK (amount > 0)');
        DB::statement(
            "ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_account_type_check
             CHECK (account_type IN ('treasury_accounts', 'economic_accounts'))"
        );

        // Append-only at the DATABASE level, exactly as audit_log is. The
        // service discipline is the suspenders; this is the belt.
        DB::statement(
            "CREATE FUNCTION ledger_entries_block_mutation() RETURNS trigger
             LANGUAGE plpgsql AS $$
             BEGIN
                 RAISE EXCEPTION 'ledger_entries is append-only: % is not permitted (a correction is a new balanced entry, never an edit)', TG_OP;
             END;
             $$"
        );
        DB::statement(
            'CREATE TRIGGER ledger_entries_immutable BEFORE DELETE OR UPDATE ON ledger_entries
             FOR EACH ROW EXECUTE FUNCTION ledger_entries_block_mutation()'
        );
        DB::statement(
            'CREATE TRIGGER ledger_entries_no_truncate BEFORE TRUNCATE ON ledger_entries
             FOR EACH STATEMENT EXECUTE FUNCTION ledger_entries_block_mutation()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS ledger_entries_no_truncate ON ledger_entries');
        DB::statement('DROP TRIGGER IF EXISTS ledger_entries_immutable ON ledger_entries');
        DB::statement('DROP FUNCTION IF EXISTS ledger_entries_block_mutation()');

        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('treasury_accounts');
        Schema::dropIfExists('currencies');
    }
};
