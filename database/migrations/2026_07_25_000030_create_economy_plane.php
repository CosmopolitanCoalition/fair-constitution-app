<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase L+M — the rest of the economy plane (slices L-3 … M-3).
 *
 * One additive migration rather than seven, because the tables reference each
 * other and the fleet shares a single migrate slot. Services and pins land
 * slice by slice on top of it.
 *
 * PRIVACY POSTURE (operator ruling 2026-07-25 — reader privacy, not
 * non-federation): every row here syncs between nodes like all other data.
 * Individual economic rows are pseudonymous — they carry an ACCOUNT id, never
 * a user id — and the single restricted object is
 * economic_account_bindings, which is what ties an account to a person. Read
 * the whole ledger from any node and you learn what moved between which
 * accounts and nothing about who. Grep this file for `user_id`: it appears
 * once, on the binding, by design. EconomyPrivacyTest pins that.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ══ L-3 — issuance: where money lawfully begins and ends ═════════
        Schema::create('issuance_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('currency_id');
            $table->string('direction', 8);           // mint | burn
            $table->decimal('amount', 24, 6);
            $table->string('reason', 160);
            $table->uuid('act_id')->nullable();       // the enacting act, when there is one
            $table->uuid('entry_group')->nullable();  // the ledger posting it produced
            $table->timestampTz('created_at');

            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->index('currency_id');
        });
        DB::statement("ALTER TABLE issuance_events ADD CONSTRAINT issuance_events_direction_check CHECK (direction IN ('mint','burn'))");
        DB::statement('ALTER TABLE issuance_events ADD CONSTRAINT issuance_events_amount_check CHECK (amount > 0)');
        $this->appendOnly('issuance_events', 'the money supply is a public record: % is not permitted');

        // ══ L-4 — budgets, which spawn the EXISTING appropriations ═══════
        Schema::create('budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('jurisdiction_id');
            $table->uuid('legislature_id')->nullable();
            $table->uuid('currency_id');
            $table->string('fiscal_label', 64);
            $table->decimal('total', 24, 6)->default(0);
            $table->string('status', 16)->default('draft');
            $table->uuid('enacting_act_id')->nullable();
            $table->timestampTz('enacted_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->index(['jurisdiction_id', 'status']);
        });
        DB::statement("ALTER TABLE budgets ADD CONSTRAINT budgets_status_check CHECK (status IN ('draft','enacted','closed'))");

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('budget_id');
            $table->string('line', 160);
            $table->decimal('amount', 24, 6);
            $table->uuid('department_id')->nullable();
            // Set when enactment spawns the appropriation row.
            $table->uuid('appropriation_id')->nullable();
            $table->timestampsTz();

            $table->foreign('budget_id')->references('id')->on('budgets')->cascadeOnDelete();
            $table->index('budget_id');
        });
        DB::statement('ALTER TABLE budget_lines ADD CONSTRAINT budget_lines_amount_check CHECK (amount > 0)');

        // ══ L-5 — revenue, levies, filings, borrowing ════════════════════
        Schema::create('revenue_streams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('jurisdiction_id');
            $table->uuid('currency_id');
            $table->string('name', 160);
            $table->string('kind', 16);               // levy | fee | transfer | other
            $table->string('status', 16)->default('proposed');
            $table->uuid('enacting_act_id')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->index(['jurisdiction_id', 'status']);
        });
        DB::statement("ALTER TABLE revenue_streams ADD CONSTRAINT revenue_streams_kind_check CHECK (kind IN ('levy','fee','transfer','other'))");
        DB::statement("ALTER TABLE revenue_streams ADD CONSTRAINT revenue_streams_status_check CHECK (status IN ('proposed','active','repealed'))");

        Schema::create('levies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('revenue_stream_id');
            $table->string('base', 24);               // income|transaction|property|per_capita|custom
            $table->decimal('rate', 9, 6);
            // Art. II §8 — the schema-level echo of the no-paywall rail. A levy
            // cannot attach to the exercise of a civic right; TRUE is the only
            // value the engine will accept for a levy touching a rights form.
            $table->boolean('civic_exempt')->default(true);
            $table->uuid('enacting_act_id')->nullable();
            $table->timestampsTz();

            $table->foreign('revenue_stream_id')->references('id')->on('revenue_streams')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE levies ADD CONSTRAINT levies_base_check CHECK (base IN ('income','transaction','property','per_capita','custom'))");
        DB::statement('ALTER TABLE levies ADD CONSTRAINT levies_rate_check CHECK (rate >= 0)');

        Schema::create('tax_filings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');               // NOT user_id — see the class docblock
            $table->uuid('levy_id')->nullable();
            $table->uuid('jurisdiction_id');
            $table->string('period', 32);
            $table->decimal('declared', 24, 6)->default(0);
            $table->decimal('assessed', 24, 6)->default(0);
            $table->string('status', 16)->default('filed');
            $table->uuid('entry_group')->nullable();
            $table->timestampsTz();

            $table->index(['account_id', 'period']);
        });
        DB::statement("ALTER TABLE tax_filings ADD CONSTRAINT tax_filings_status_check CHECK (status IN ('filed','assessed','settled','disputed'))");

        Schema::create('borrowings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('jurisdiction_id');
            $table->uuid('currency_id');
            $table->uuid('lender_account_id')->nullable();
            $table->decimal('principal', 24, 6);
            $table->text('terms');
            $table->string('status', 16)->default('authorised');
            $table->uuid('enacting_act_id')->nullable();
            $table->timestampsTz();

            $table->foreign('currency_id')->references('id')->on('currencies');
        });
        DB::statement("ALTER TABLE borrowings ADD CONSTRAINT borrowings_status_check CHECK (status IN ('authorised','drawn','repaid','defaulted'))");
        DB::statement('ALTER TABLE borrowings ADD CONSTRAINT borrowings_principal_check CHECK (principal > 0)');

        // ══ M-1 — individual & organisational money ══════════════════════
        // PSEUDONYMOUS. No user_id here; the binding below is the only link.
        Schema::create('economic_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 16);               // user | organization
            $table->uuid('currency_id');
            $table->decimal('balance', 24, 6)->default(0);
            $table->string('status', 16)->default('open');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('currency_id')->references('id')->on('currencies');
        });
        DB::statement("ALTER TABLE economic_accounts ADD CONSTRAINT economic_accounts_kind_check CHECK (kind IN ('user','organization'))");
        DB::statement("ALTER TABLE economic_accounts ADD CONSTRAINT economic_accounts_status_check CHECK (status IN ('open','frozen','closed'))");

        // THE one restricted object in the whole economy. Everything else can
        // be read freely on any node and reveals nothing about who.
        Schema::create('economic_account_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('owner_type', 16);         // users | organizations
            $table->uuid('owner_id');
            $table->timestampsTz();

            $table->foreign('account_id')->references('id')->on('economic_accounts')->cascadeOnDelete();
            $table->unique('account_id');
            $table->index(['owner_type', 'owner_id']);
        });
        DB::statement("ALTER TABLE economic_account_bindings ADD CONSTRAINT economic_account_bindings_owner_type_check CHECK (owner_type IN ('users','organizations'))");

        Schema::create('market_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('from_account_id')->nullable();
            $table->uuid('to_account_id')->nullable();
            $table->uuid('currency_id');
            $table->decimal('amount', 24, 6);
            $table->string('kind', 24);               // transfer|order|stipend|levy|dues|grant|donation
            $table->string('memo', 240)->nullable();
            $table->uuid('entry_group')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->index('from_account_id');
            $table->index('to_account_id');
        });
        DB::statement('ALTER TABLE market_transactions ADD CONSTRAINT market_transactions_amount_check CHECK (amount > 0)');

        // ══ L-6 — the civic stipend ══════════════════════════════════════
        // Public AGGREGATE.
        Schema::create('ubi_disbursements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('jurisdiction_id');
            $table->uuid('currency_id');
            $table->timestampTz('ran_at');
            $table->integer('recipients');
            $table->decimal('total', 24, 6);
            $table->string('funding_source', 16);     // minted | treasury_draw
            $table->boolean('short_paid')->default(false);
            $table->decimal('short_pay_ratio', 9, 6)->nullable();
            $table->uuid('entry_group')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('currency_id')->references('id')->on('currencies');
        });
        DB::statement("ALTER TABLE ubi_disbursements ADD CONSTRAINT ubi_disbursements_funding_check CHECK (funding_source IN ('minted','treasury_draw'))");

        // Per-recipient line — account-scoped, so it inherits the privacy
        // posture with no special case.
        Schema::create('ubi_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('disbursement_id');
            $table->uuid('account_id');
            $table->decimal('base', 24, 6);
            $table->decimal('bump', 24, 6)->default(0);
            $table->decimal('amount', 24, 6);
            $table->timestampTz('created_at');

            $table->foreign('disbursement_id')->references('id')->on('ubi_disbursements')->cascadeOnDelete();
            $table->index('account_id');
            $table->unique(['disbursement_id', 'account_id']);
        });

        // ══ M-2 — the labour board (a front door onto a BUILT chain) ═════
        Schema::create('work_postings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('title', 160);
            $table->text('terms');
            $table->decimal('rate', 24, 6)->nullable();
            $table->uuid('currency_id')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'status']);
        });
        DB::statement("ALTER TABLE work_postings ADD CONSTRAINT work_postings_status_check CHECK (status IN ('open','filled','closed'))");

        Schema::create('work_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('posting_id');
            $table->uuid('applicant_account_id');
            $table->text('note')->nullable();
            $table->string('status', 16)->default('applied');
            // The F-IND-014 filing acceptance produced. Proof the labour board
            // is a front door and never a bypass of Art. III §6.
            $table->uuid('org_contract_id')->nullable();
            $table->timestampsTz();

            $table->foreign('posting_id')->references('id')->on('work_postings')->cascadeOnDelete();
            $table->unique(['posting_id', 'applicant_account_id']);
        });
        DB::statement("ALTER TABLE work_applications ADD CONSTRAINT work_applications_status_check CHECK (status IN ('applied','accepted','declined','withdrawn'))");

        // ══ M-3 — assets, market, mutual aid, joint ledgers ══════════════
        // Assets are the INVENTORY model (operator ruling): a seller lists a
        // specific thing, and the thing has its own identity and attributes.
        // One flag for physical vs virtual rather than two systems — which is
        // what lets a craft economy and the fantasy maps run on one engine.
        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_account_id');
            $table->string('kind', 16);               // physical | virtual
            $table->string('name', 160);
            $table->text('description')->nullable();
            // Open on purpose: the app does not get to enumerate what people
            // may make. A blanket carries materials; a game item carries stats.
            $table->jsonb('attributes')->nullable();
            $table->decimal('quantity', 24, 6)->default(1);
            $table->string('origin', 16)->default('crafted');
            $table->uuid('created_by_account_id')->nullable();
            $table->uuid('jurisdiction_id')->nullable(); // where a physical thing is
            $table->string('status', 16)->default('held');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('owner_account_id');
            $table->index('kind');
        });
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_kind_check CHECK (kind IN ('physical','virtual'))");
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_origin_check CHECK (origin IN ('crafted','imported','issued','split'))");
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_status_check CHECK (status IN ('held','listed','escrow','transferred','retired'))");
        DB::statement('ALTER TABLE assets ADD CONSTRAINT assets_quantity_check CHECK (quantity > 0)');

        Schema::create('asset_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id');
            $table->uuid('from_account_id')->nullable();
            $table->uuid('to_account_id');
            $table->decimal('quantity', 24, 6);
            $table->uuid('order_id')->nullable();
            $table->uuid('contract_id')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->index('asset_id');
        });
        $this->appendOnly('asset_transfers', 'an asset provenance record is append-only: % is not permitted');

        Schema::create('marketplace_listings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('seller_account_id');
            $table->string('kind', 16);               // good | service
            $table->uuid('asset_id')->nullable();     // a good points at a thing
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->decimal('quantity', 24, 6)->default(1);
            $table->decimal('price', 24, 6);
            $table->uuid('currency_id');
            $table->string('status', 16)->default('open');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->index(['status', 'kind']);
        });
        DB::statement("ALTER TABLE marketplace_listings ADD CONSTRAINT marketplace_listings_kind_check CHECK (kind IN ('good','service'))");
        DB::statement("ALTER TABLE marketplace_listings ADD CONSTRAINT marketplace_listings_status_check CHECK (status IN ('open','reserved','sold','withdrawn'))");
        DB::statement('ALTER TABLE marketplace_listings ADD CONSTRAINT marketplace_listings_price_check CHECK (price >= 0)');

        Schema::create('marketplace_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('listing_id');
            $table->uuid('buyer_account_id');
            $table->decimal('quantity', 24, 6)->default(1);
            $table->decimal('price', 24, 6);
            $table->string('status', 16)->default('placed');
            // An accepted order becomes a commercial org_contract — the
            // primitive that already exists in schema with a two-signature
            // cosign constraint and, until now, no writer.
            $table->uuid('contract_id')->nullable();
            $table->uuid('entry_group')->nullable();
            $table->timestampsTz();

            $table->foreign('listing_id')->references('id')->on('marketplace_listings')->cascadeOnDelete();
            $table->index('buyer_account_id');
        });
        DB::statement("ALTER TABLE marketplace_orders ADD CONSTRAINT marketplace_orders_status_check CHECK (status IN ('placed','accepted','settled','cancelled'))");

        // Mutual aid is NON-market: no price, no order, no fee. Art. I
        // association, private by default.
        Schema::create('assistance_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('requester_account_id');
            $table->uuid('jurisdiction_id')->nullable();
            $table->string('title', 160);
            $table->text('need');
            $table->string('privacy', 16)->default('private');
            $table->string('status', 16)->default('open');
            $table->uuid('responder_account_id')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
        });
        DB::statement("ALTER TABLE assistance_requests ADD CONSTRAINT assistance_requests_privacy_check CHECK (privacy IN ('private','jurisdiction','public'))");
        DB::statement("ALTER TABLE assistance_requests ADD CONSTRAINT assistance_requests_status_check CHECK (status IN ('open','matched','resolved','withdrawn'))");

        // Art. V §2 — shared and indivisible resources. A joint ledger moves
        // only when its approval rule is met.
        Schema::create('joint_ledgers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->text('purpose')->nullable();
            $table->uuid('currency_id');
            $table->decimal('balance', 24, 6)->default(0);
            $table->string('approval_rule', 16)->default('all');
            $table->boolean('public')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('currency_id')->references('id')->on('currencies');
        });
        DB::statement("ALTER TABLE joint_ledgers ADD CONSTRAINT joint_ledgers_approval_rule_check CHECK (approval_rule IN ('all','majority'))");

        Schema::create('joint_ledger_parties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('joint_ledger_id');
            $table->uuid('account_id');
            $table->string('role', 32)->default('signer');
            $table->timestampsTz();

            $table->foreign('joint_ledger_id')->references('id')->on('joint_ledgers')->cascadeOnDelete();
            $table->unique(['joint_ledger_id', 'account_id']);
        });

        Schema::create('joint_ledger_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('joint_ledger_id');
            $table->uuid('to_account_id');
            $table->decimal('amount', 24, 6);
            $table->string('memo', 240)->nullable();
            $table->jsonb('approvals')->nullable();   // account ids that have signed
            $table->string('status', 16)->default('pending');
            $table->uuid('entry_group')->nullable();
            $table->timestampsTz();

            $table->foreign('joint_ledger_id')->references('id')->on('joint_ledgers')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE joint_ledger_movements ADD CONSTRAINT joint_ledger_movements_status_check CHECK (status IN ('pending','settled','rejected'))");
        DB::statement('ALTER TABLE joint_ledger_movements ADD CONSTRAINT joint_ledger_movements_amount_check CHECK (amount > 0)');
    }

    private function appendOnly(string $table, string $message): void
    {
        $fn = "{$table}_block_mutation";

        DB::statement(
            "CREATE FUNCTION {$fn}() RETURNS trigger LANGUAGE plpgsql AS $$
             BEGIN
                 RAISE EXCEPTION '{$message}', TG_OP;
             END;
             $$"
        );
        DB::statement(
            "CREATE TRIGGER {$table}_immutable BEFORE DELETE OR UPDATE ON {$table}
             FOR EACH ROW EXECUTE FUNCTION {$fn}()"
        );
    }

    public function down(): void
    {
        foreach (['asset_transfers', 'issuance_events'] as $t) {
            DB::statement("DROP TRIGGER IF EXISTS {$t}_immutable ON {$t}");
            DB::statement("DROP FUNCTION IF EXISTS {$t}_block_mutation()");
        }

        foreach ([
            'joint_ledger_movements', 'joint_ledger_parties', 'joint_ledgers',
            'assistance_requests', 'marketplace_orders', 'marketplace_listings',
            'asset_transfers', 'assets',
            'work_applications', 'work_postings',
            'ubi_receipts', 'ubi_disbursements',
            'market_transactions', 'economic_account_bindings', 'economic_accounts',
            'borrowings', 'tax_filings', 'levies', 'revenue_streams',
            'budget_lines', 'budgets', 'issuance_events',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
