<?php

namespace Tests\Constitutional;

use App\Services\Economy\AssetService;
use App\Services\Economy\LaborBoardService;
use App\Services\Economy\StipendService;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Phase M — the pins that make the privacy model structural rather than a
 * promise, plus the two rails that keep money out of governance.
 *
 * The operator ruled (2026-07-25) that economic records SYNC between nodes and
 * that privacy is READER privacy, like a ballot. That ruling is only worth
 * anything if the sync-safe shape is enforced: pseudonymous accounts
 * everywhere, and exactly ONE table that says who owns one.
 */
class EconomyPrivacyTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'economy_privacy_pg';

    /**
     * The economy's tables carry ACCOUNT ids, never user ids. Exactly one
     * table binds an account to a person, and if a second ever appears the
     * whole reader-privacy model collapses silently — so it fails here loudly.
     */
    public function test_only_the_binding_table_links_an_account_to_a_person(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $economyTables = [
            'ledger_entries', 'treasury_accounts', 'currencies', 'issuance_events',
            'economic_accounts', 'market_transactions',
            'ubi_disbursements', 'ubi_receipts',
            'tax_filings', 'budgets', 'budget_lines', 'revenue_streams', 'levies', 'borrowings',
            'assets', 'asset_transfers',
            'marketplace_listings', 'marketplace_orders',
            'work_postings', 'work_applications',
            'assistance_requests', 'joint_ledgers', 'joint_ledger_parties', 'joint_ledger_movements',
        ];

        $offenders = [];

        foreach ($economyTables as $table) {
            $columns = $conn->select(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_name = ? AND column_name IN ('user_id','users_id','owner_user_id','applicant_user_id')",
                [$table]
            );

            foreach ($columns as $column) {
                $offenders[] = "{$table}.{$column->column_name}";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Economy rows must reference accounts, not people. Offending columns: ' . implode(', ', $offenders)
        );

        // And the binding really does exist — otherwise the assertion above
        // would pass trivially on an empty schema.
        $binding = $conn->select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_name = 'economic_account_bindings' AND column_name = 'owner_id'"
        );
        $this->assertCount(1, $binding, 'economic_account_bindings.owner_id is the one lawful link');
    }

    /**
     * Art. I — the stipend gate is active residency and NOTHING else. If a
     * second condition ever appears in the eligibility surface, this fails.
     */
    public function test_the_stipend_gate_carries_no_condition_beyond_residency(): void
    {
        $source = file_get_contents((new ReflectionClass(StipendService::class))->getFileName());

        // The forbidden shapes: anything that would make payment conditional
        // on something other than being a resident.
        foreach (['age_of_majority', 'age_of_consent', 'is_verified', 'good_standing', 'minimum_balance', 'achievement'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "The civic stipend may carry no condition beyond active residency; found [{$forbidden}]."
            );
        }
    }

    /** Money may never gate governance: no economy table in a rights path. */
    public function test_no_role_or_eligibility_path_reads_the_economy(): void
    {
        $economyNames = [
            'economic_accounts', 'ledger_entries', 'ubi_receipts',
            'market_transactions', 'tax_filings', 'treasury_accounts',
        ];

        $rightsPaths = [
            app_path('Services/RoleService.php'),
            app_path('Services/ConstitutionalValidator.php'),
        ];

        foreach ($rightsPaths as $path) {
            if (! is_file($path)) {
                continue;
            }

            $source = file_get_contents($path);

            foreach ($economyNames as $name) {
                $this->assertStringNotContainsString(
                    $name,
                    $source,
                    basename($path) . " must not read [{$name}] — no money fact may gate a governance act."
                );
            }
        }
    }

    /**
     * Art. III §6 — the labour board is a front door, never a bypass. It must
     * file F-IND-014 and must not write the headcount tables itself.
     */
    public function test_the_labour_board_hires_only_through_the_constitutional_form(): void
    {
        $source = file_get_contents((new ReflectionClass(LaborBoardService::class))->getFileName());

        $this->assertStringContainsString(
            "file('F-IND-014'",
            $source,
            'A hire must go through F-IND-014 so co-determination cannot be bypassed.'
        );

        foreach (['org_workers', 'worker_headcount', 'worker_seats'] as $forbidden) {
            $this->assertStringNotContainsString(
                "table('{$forbidden}')",
                $source,
                "The labour board must not write [{$forbidden}] — that is CoDeterminationService's, and it is PROTECTED."
            );
        }
    }

    /** The stipend bump is capped on the SUM, not per role. */
    public function test_stacked_role_bumps_are_capped_in_total(): void
    {
        $stipend = app(StipendService::class);

        $bumps = ['node_operator' => '8', 'social_moderator' => '5', 'office_holder' => '12'];

        // 8 + 5 + 12 = 25, capped at 20.
        $this->assertSame(
            0,
            bccomp($stipend->bumpFor(['node_operator', 'social_moderator', 'office_holder'], $bumps, '20'), '20', 6)
        );

        // Under the cap, the true sum.
        $this->assertSame(0, bccomp($stipend->bumpFor(['node_operator', 'social_moderator'], $bumps, '20'), '13', 6));

        // No roles: the floor alone, no bump.
        $this->assertSame(0, bccomp($stipend->bumpFor([], $bumps, '20'), '0', 6));

        // A role claimed twice cannot be paid twice.
        $this->assertSame(0, bccomp($stipend->bumpFor(['office_holder', 'office_holder'], $bumps, '20'), '12', 6));
    }

    /** k-anonymity: a class too small to publish folds into the general total. */
    public function test_small_stipend_classes_are_suppressed(): void
    {
        $stipend = app(StipendService::class);

        $result = $stipend->suppressSmallClasses([
            'office_holder' => ['recipients' => 1, 'total' => '12'],   // inferable → suppress
            'node_operator' => ['recipients' => 50, 'total' => '400'], // safe → publish
        ]);

        $this->assertArrayNotHasKey('office_holder', $result, 'a one-member class must never be published');
        $this->assertSame('400', $result['node_operator']);
        $this->assertSame(0, bccomp($result['general'], '12', 6), 'the suppressed class folds into the general total');
    }

    /** An asset is physical or virtual — one engine, not two systems. */
    public function test_assets_span_physical_and_virtual(): void
    {
        $this->assertSame('physical', AssetService::KIND_PHYSICAL);
        $this->assertSame('virtual', AssetService::KIND_VIRTUAL);
    }

    // ─── Art. II §8 — the first general no-fee rail (slice L-5) ──────────

    /**
     * A fee may not be attached to ANY form, at ANY depth. The older
     * rights-automatic guard covers six forms and top-level keys only; this
     * covers the other 102.
     */
    public function test_no_form_may_carry_a_charge_for_filing(): void
    {
        $validator = app(\App\Services\ConstitutionalValidator::class);

        // A non-rights-automatic form, nested three deep.
        try {
            $validator->check('F-ORG-001', ['action' => 'x', 'meta' => ['billing' => ['fee' => 25]]]);
            $this->fail('a nested fee must be rejected on any form');
        } catch (\App\Domain\Engine\ConstitutionalViolation $e) {
            $this->assertSame('Art. II §8', $e->citation);
            $this->assertStringContainsString('meta.billing.fee', $e->getMessage());
        }

        // The rights-automatic forms keep their narrower Art. I citation.
        try {
            $validator->check('F-IND-016', ['fee' => 100]);
            $this->fail('a fee on a rights-automatic form must be rejected');
        } catch (\App\Domain\Engine\ConstitutionalViolation $e) {
            $this->assertSame('Art. I', $e->citation);
        }
    }

    /** Money still moves: transaction fields are not tolls. */
    public function test_ordinary_money_fields_are_not_fees(): void
    {
        $validator = app(\App\Services\ConstitutionalValidator::class);

        // Must not throw — the economy is full of lawful amounts.
        $validator->check('F-ORG-001', [
            'action' => 'x',
            'amount' => 500,
            'price'  => 25,
            'rate'   => 0.05,
            'total'  => 525,
        ]);

        $this->assertTrue(true, 'amount/price/rate/total are transaction fields, not charges on an act');
    }
}
