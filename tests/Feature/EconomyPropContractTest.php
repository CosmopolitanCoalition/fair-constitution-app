<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * The prop contract, enforced.
 *
 * @lane-06 builds pages against docs/plans/economy/ECONOMY_PROP_CONTRACT.md.
 * This asserts the LIVE controllers ship exactly the keys published there, so
 * the contract cannot drift out from under their pages.
 *
 * It exists because of a specific, expensive failure: the v3 mapper's
 * "Proposing…" freeze was prop-contract drift between two parallel agents —
 * a panel read `plan.total_pop`, the backend never shipped it,
 * `formatPop(undefined).toLocaleString()` threw INSIDE render, the Vue patch
 * aborted, and the DOM froze at the last paint. It presented as a hang, not a
 * type error, and cost a day to find.
 *
 * So the assertions here are about SHAPE, not values:
 *   - every documented key is present (a missing key is what kills a render)
 *   - collections are arrays, never null
 *   - money is a STRING (numeric(24,6); a float loses precision on a ledger)
 *   - the only nullable fields are the ones the contract names
 */
class EconomyPropContractTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'economy_props_pg';

    /**
     * The published shape, key-for-key. Update the doc and this together.
     * `surface` joined every page in Wave 2 — the SurfaceMeta record the
     * shell, the Learn flyout and the footer citation all read.
     */
    private const CONTRACT = [
        '/economy'                => ['surface', 'currency', 'supply', 'ledger', 'counts', 'stipend'],
        // `assets` / `my_assets` arrived with the write path (F-IND-022/024):
        // a page cannot offer a thing without knowing what you hold.
        '/economy/wallet'         => ['surface', 'currency', 'account', 'transactions', 'receipts', 'assets'],
        '/economy/market'         => ['surface', 'currency', 'offers', 'work', 'assistance', 'my_assets'],
        '/economy/treasury'       => ['surface', 'currency', 'accounts', 'ledger', 'issuance', 'budgets', 'revenue', 'borrowings', 'totals'],
        '/economy/units'          => ['surface', 'currency', 'levers', 'supply', 'issuance_rate_bps', 'inflation_target_bps'],
        '/economy/stipend'        => ['surface', 'currency', 'stipend', 'clock', 'k_anon_floor', 'examples'],
        '/economy/agreements'     => ['surface', 'agreements'],
        '/economy/joint-ledgers'  => ['surface', 'currency', 'ledgers', 'can_open', 'my_account_id'],
        // Design Round 2 build: the exchange (① instruments venue) and the
        // resident consent plane (③ person-to-person / N-party agreements).
        '/economy/exchange'            => ['surface', 'currency', 'instruments', 'shares', 'order_book'],
        '/economy/resident-agreements' => ['surface', 'agreements', 'candidates', 'my_id'],
    ];

    private const ALWAYS_ARRAY = [
        // A guest, or a resident with no wallet, holds nothing — and "nothing"
        // is [], never null. A missing key is what kills a render.
        '/economy/wallet'   => ['transactions', 'receipts', 'assets'],
        '/economy/market'   => ['offers', 'work', 'assistance', 'my_assets'],
        '/economy/treasury' => ['accounts', 'ledger', 'issuance', 'budgets', 'revenue', 'borrowings'],
        '/economy/units'    => ['levers'],
        '/economy/stipend'  => ['examples'],
        '/economy/agreements' => ['agreements'],
        '/economy/joint-ledgers' => ['ledgers'],
        '/economy/exchange' => ['instruments', 'shares'],
        '/economy/resident-agreements' => ['agreements', 'candidates'],
    ];

    private function actor(): ?User
    {
        $this->livePg(self::LIVE_CONNECTION);
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $user = User::query()->first();

        if ($user === null) {
            $this->markTestSkipped('No users on this box — the economy surfaces need a founded world.');
        }

        return $user;
    }

    public function test_every_economy_surface_ships_its_published_props(): void
    {
        $user = $this->actor();

        foreach (self::CONTRACT as $url => $keys) {
            $props = $this->actingAs($user)->get($url)
                ->assertOk()
                ->viewData('page')['props'];

            foreach ($keys as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $props,
                    "{$url} must ship [{$key}] — the contract publishes it and a missing key kills the render."
                );
            }
        }
    }

    public function test_collections_are_arrays_never_null(): void
    {
        $user = $this->actor();

        foreach (self::ALWAYS_ARRAY as $url => $keys) {
            $props = $this->actingAs($user)->get($url)->assertOk()->viewData('page')['props'];

            foreach ($keys as $key) {
                $this->assertIsArray(
                    $props[$key],
                    "{$url}.{$key} must be an array — an empty market is [], never null."
                );
            }
        }
    }

    /** numeric(24,6) through a float silently loses precision on a ledger. */
    public function test_money_crosses_the_boundary_as_a_string(): void
    {
        $user = $this->actor();

        $home = $this->actingAs($user)->get('/economy')->assertOk()->viewData('page')['props'];

        $this->assertIsString($home['supply'], 'supply must be a string');
        $this->assertIsString($home['ledger']['residual'], 'residual must be a string');
        $this->assertIsString($home['stipend']['floor'], 'stipend.floor must be a string');
        $this->assertIsString($home['stipend']['cap'], 'stipend.cap must be a string');

        $treasury = $this->actingAs($user)->get('/economy/treasury')->assertOk()->viewData('page')['props'];

        $this->assertIsString($treasury['totals']['supply']);
        $this->assertIsString($treasury['totals']['treasury_balance']);

        foreach ($treasury['accounts'] as $account) {
            $this->assertIsString($account['balance'], 'every treasury balance must be a string');
        }

        foreach ($treasury['budgets'] as $budget) {
            foreach ($budget['line_items'] as $line) {
                $this->assertIsString($line['amount'], 'a budget line amount is money, and money is a string');
            }
        }

        foreach ($treasury['borrowings'] as $borrowing) {
            $this->assertIsString($borrowing['principal'], 'a borrowing principal is money, and money is a string');
        }
    }

    /**
     * The seller-identity boundary, drawn exactly (Wave 2 item 5): an
     * ORGANIZATION seller resolves to a name — its listing is its public
     * act — while a HUMAN seller never resolves past the account. If this
     * fails, either the CGC badge died or a person got named.
     */
    public function test_seller_identity_resolves_orgs_and_never_people(): void
    {
        $user = $this->actor();

        $props = $this->actingAs($user)->get('/economy/market')->assertOk()->viewData('page')['props'];

        foreach ($props['offers'] as $offer) {
            $this->assertArrayHasKey('seller_org', $offer, 'every offer declares its seller_org (null for a person)');

            $ownerType = DB::table('economic_account_bindings')
                ->where('account_id', $offer['seller_account_id'])
                ->value('owner_type');

            if ($ownerType === 'organizations') {
                $this->assertNotNull($offer['seller_org'], 'an organization seller resolves to its public name');
                $this->assertArrayHasKey('is_cgc', $offer['seller_org']);
            } else {
                $this->assertNull($offer['seller_org'], 'a human seller must never resolve past the account');
            }
        }
    }

    /**
     * The ledger's own invariant, surfaced. If this reads anything but zero on
     * a live box, value has entered or left outside an issuance event.
     */
    public function test_the_home_surface_reports_a_conserved_ledger(): void
    {
        $user = $this->actor();

        $props = $this->actingAs($user)->get('/economy')->assertOk()->viewData('page')['props'];

        $this->assertTrue($props['ledger']['verified'], 'the ledger hash chain must verify');
        $this->assertSame(
            0,
            bccomp($props['ledger']['residual'], '0', 6),
            'residual = Σdebits − Σcredits + minted supply, and must be zero: issuance is the only lawful way for value to enter.'
        );
    }

    /** Private mutual-aid requests never cross the boundary. */
    public function test_private_assistance_requests_are_not_published(): void
    {
        $user = $this->actor();

        $props = $this->actingAs($user)->get('/economy/market')->assertOk()->viewData('page')['props'];

        foreach ($props['assistance'] as $request) {
            $this->assertNotSame(
                'private',
                $request['privacy'],
                'A private mutual-aid request must never appear on a public board.'
            );
        }
    }

    /** No page ever receives a user id — accounts only (the privacy model). */
    public function test_no_surface_leaks_a_user_id(): void
    {
        $user = $this->actor();

        foreach (array_keys(self::CONTRACT) as $url) {
            $props = $this->actingAs($user)->get($url)->assertOk()->viewData('page')['props'];
            $json  = json_encode($props);

            $this->assertStringNotContainsString(
                '"user_id"',
                (string) $json,
                "{$url} must not ship a user_id — economy rows are account-scoped."
            );
        }
    }

    /**
     * The work-posting page (per-posting URL, so it cannot sit in CONTRACT).
     * Its thresholds must arrive RESOLVED — the page never computes or
     * hardcodes 100/2000; those values legislate (Art. III §6 settings).
     */
    public function test_the_work_posting_surface_ships_its_published_props(): void
    {
        $user = $this->actor();

        $postingId = DB::table('work_postings')->whereNull('deleted_at')->value('id');

        if ($postingId === null) {
            $this->markTestSkipped('No work postings on this box — institutions:demo-treasury seeds one.');
        }

        $props = $this->actingAs($user)->get("/economy/requests/{$postingId}")
            ->assertOk()
            ->viewData('page')['props'];

        foreach (['surface', 'currency', 'posting', 'codetermination', 'can_apply', 'has_applied'] as $key) {
            $this->assertArrayHasKey($key, $props, "request-detail must ship [{$key}].");
        }

        foreach (['id', 'title', 'terms', 'rate', 'status', 'org_name', 'applications', 'at'] as $key) {
            $this->assertArrayHasKey($key, $props['posting'], "posting must carry [{$key}].");
        }

        foreach (['first_seat_at', 'parity_at', 'headcount'] as $key) {
            $this->assertArrayHasKey($key, $props['codetermination'], "codetermination must carry [{$key}].");
            $this->assertIsInt($props['codetermination'][$key], "codetermination.{$key} is a resolved integer.");
        }

        if ($props['posting']['rate'] !== null) {
            $this->assertIsString($props['posting']['rate'], 'a rate is money, and money is a string');
        }
    }

    /**
     * AN INSTRUMENT IS PARTY-SCOPED. The terms of an agreement never reach a
     * non-party — the register lists only the viewer's own, and the detail
     * 404s (not 403: a non-party is not even told the instrument exists).
     */
    public function test_an_agreement_is_invisible_to_a_non_party(): void
    {
        $this->actor();

        $contract = DB::table('org_contracts')->whereNull('deleted_at')->first();

        if ($contract === null) {
            $this->markTestSkipped('No contracts on this box — the demo chain seeds one.');
        }

        // A user who is not the counterparty, not the org signer, and holds
        // no active membership in the organization.
        $outsider = User::query()
            ->when($contract->counterparty_type === 'users', fn ($q) => $q->where('id', '!=', $contract->counterparty_id))
            ->when($contract->signed_by_org_user_id !== null, fn ($q) => $q->where('id', '!=', $contract->signed_by_org_user_id))
            ->whereNotIn('id', DB::table('org_memberships')
                ->where('organization_id', $contract->organization_id)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->select('user_id'))
            ->first();

        if ($outsider === null) {
            $this->markTestSkipped('Everyone on this box is a party to the only contract.');
        }

        $this->actingAs($outsider)->get("/economy/agreements/{$contract->id}")->assertNotFound();

        $register = $this->actingAs($outsider)->get('/economy/agreements')->assertOk()->viewData('page')['props'];

        $this->assertNotContains(
            (string) $contract->id,
            array_column($register['agreements'], 'id'),
            'the register must not list an instrument the viewer is no party to'
        );
    }

    /** And a PARTY sees it — the scoping refuses outsiders, not everyone. */
    public function test_a_party_sees_their_own_agreement(): void
    {
        $this->actor();

        $contract = DB::table('org_contracts')
            ->whereNull('deleted_at')
            ->where('counterparty_type', 'users')
            ->first();

        if ($contract === null) {
            $this->markTestSkipped('No user-side contracts on this box.');
        }

        $party = User::query()->find($contract->counterparty_id);

        if ($party === null) {
            $this->markTestSkipped('The counterparty user is not on this box.');
        }

        $props = $this->actingAs($party)->get("/economy/agreements/{$contract->id}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('You', $props['agreement']['counterparty'], 'a party sees themselves as You');
        $this->assertArrayHasKey('terms_full', $props['agreement'], 'a party reads the full terms');
    }

    /** Stipend-page money is strings, and the examples ride the real formula. */
    public function test_the_stipend_surface_keeps_money_as_strings(): void
    {
        $user = $this->actor();

        $props = $this->actingAs($user)->get('/economy/stipend')->assertOk()->viewData('page')['props'];

        $this->assertIsString($props['stipend']['floor']);
        $this->assertIsString($props['stipend']['cap']);

        foreach ($props['stipend']['bumps'] as $class => $bump) {
            $this->assertIsString($bump, "stipend.bumps.{$class} must be a string");
        }

        $this->assertIsInt($props['k_anon_floor']);

        foreach ($props['examples'] as $example) {
            foreach (['base', 'bump', 'amount'] as $key) {
                $this->assertIsString($example[$key], "examples[].{$key} must be a string");
            }

            // The example is the formula, verbatim: amount = base + bump.
            $this->assertSame(
                0,
                bccomp($example['amount'], bcadd($example['base'], $example['bump'], 6), 6),
                'a worked example must be the live formula, not hand-arithmetic'
            );
        }
    }
}
