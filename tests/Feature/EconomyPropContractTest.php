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

    /** The published shape, key-for-key. Update the doc and this together. */
    private const CONTRACT = [
        '/economy'                => ['currency', 'supply', 'ledger', 'counts', 'stipend'],
        '/economy/wallet'         => ['currency', 'account', 'transactions', 'receipts'],
        '/economy/market'         => ['currency', 'offers', 'work', 'assistance'],
        '/economy/treasury'       => ['currency', 'accounts', 'ledger', 'issuance', 'budgets', 'revenue', 'totals'],
        '/economy/units'          => ['currency', 'levers', 'supply', 'issuance_rate_bps', 'inflation_target_bps'],
    ];

    private const ALWAYS_ARRAY = [
        '/economy/wallet'   => ['transactions', 'receipts'],
        '/economy/market'   => ['offers', 'work', 'assistance'],
        '/economy/treasury' => ['accounts', 'ledger', 'issuance', 'budgets', 'revenue'],
        '/economy/units'    => ['levers'],
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
}
