<?php

namespace Tests\Feature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Economy\Currency;
use App\Models\Organization;
use App\Models\OrgOwnershipStake;
use App\Models\User;
use App\Services\Economy\AccountService;
use App\Services\Economy\ShareTradeService;
use App\Services\Organizations\OrgOwnershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Wave 4 ② — secondary share trading (F-IND-021 / ShareTradeService).
 *
 * The two planes stay apart: units move on the NAMED ownership plane (Ruling B),
 * money on the pseudonymous account plane, linked only by money_transfer_id. A
 * refusal is an answer — an underfunded buy moves NOTHING (both legs roll back).
 */
class ShareTradeTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'share_trade_pg';

    public function test_offer_then_buy_moves_units_and_money(): void
    {
        $this->onLivePg(function () {
            [$org, $seller, $currency] = $this->stockOrgWithHolder(1000.0);
            $buyer = $this->user();
            $this->fund($buyer, $currency, '5000');

            $trades = app(ShareTradeService::class);

            $offer = $trades->offer($seller, (string) $org->id, 400.0, '2.000000');
            $trades->buy($buyer, $offer['offer_id']);

            // Ownership moved: seller 600, buyer 400.
            $this->assertEqualsWithDelta(600.0, $this->held($org->id, $seller->id), 0.0001, 'seller keeps the remainder');
            $this->assertEqualsWithDelta(400.0, $this->held($org->id, $buyer->id), 0.0001, 'buyer receives the units');

            // Money moved: 400 × 2 = 800, buyer 5000 → 4200, seller 0 → 800.
            $accounts = app(AccountService::class);
            $buyerAcct = $accounts->accountIdFor('users', (string) $buyer->id, (string) $currency->id);
            $sellerAcct = $accounts->accountIdFor('users', (string) $seller->id, (string) $currency->id);
            $this->assertSame('4200.000000', (string) DB::table('economic_accounts')->where('id', $buyerAcct)->value('balance'));
            $this->assertSame('800.000000', (string) DB::table('economic_accounts')->where('id', $sellerAcct)->value('balance'));

            // The offer settled, and the money leg is linked but carries no name.
            $row = DB::table('share_offers')->where('id', $offer['offer_id'])->first();
            $this->assertSame('filled', $row->status);
            $this->assertNotNull($row->money_transfer_id);
            $this->assertNotNull($row->settled_at);
        });
    }

    public function test_a_buy_without_funds_refuses_and_rolls_back_both_legs(): void
    {
        $this->onLivePg(function () {
            [$org, $seller, $currency] = $this->stockOrgWithHolder(1000.0);
            $buyer = $this->user();
            $this->fund($buyer, $currency, '10'); // nowhere near 400 × 2

            $trades = app(ShareTradeService::class);
            $offer = $trades->offer($seller, (string) $org->id, 400.0, '2.000000');

            try {
                $trades->buy($buyer, $offer['offer_id']);
                $this->fail('an underfunded buy must be refused');
            } catch (ConstitutionalViolation | \RuntimeException | \InvalidArgumentException) {
                // expected — no overdraft. AccountService::assertSufficient throws
                // InvalidArgumentException at the service level; the F-IND-021
                // handler's wrap() turns it into a cited ConstitutionalViolation.
            }

            // BOTH legs rolled back: units unchanged, offer still open.
            $this->assertEqualsWithDelta(1000.0, $this->held($org->id, $seller->id), 0.0001, 'seller still holds everything');
            $this->assertEqualsWithDelta(0.0, $this->held($org->id, $buyer->id), 0.0001, 'buyer received nothing');
            $this->assertSame('open', (string) DB::table('share_offers')->where('id', $offer['offer_id'])->value('status'));
        });
    }

    public function test_cannot_offer_more_than_held(): void
    {
        $this->onLivePg(function () {
            [$org, $seller] = $this->stockOrgWithHolder(100.0);

            $this->expectException(ConstitutionalViolation::class);
            app(ShareTradeService::class)->offer($seller, (string) $org->id, 500.0, '1.000000');
        });
    }

    public function test_only_the_seller_cancels(): void
    {
        $this->onLivePg(function () {
            [$org, $seller] = $this->stockOrgWithHolder(100.0);
            $stranger = $this->user();

            $trades = app(ShareTradeService::class);
            $offer = $trades->offer($seller, (string) $org->id, 50.0, '1.000000');

            try {
                $trades->cancel($stranger, $offer['offer_id']);
                $this->fail('a stranger cannot cancel the offer');
            } catch (ConstitutionalViolation) {
                // expected
            }

            $trades->cancel($seller, $offer['offer_id']);
            $this->assertSame('cancelled', (string) DB::table('share_offers')->where('id', $offer['offer_id'])->value('status'));
        });
    }

    public function test_the_engine_door_files_a_share_trade(): void
    {
        $this->onLivePg(function () {
            [$org, $seller, $currency] = $this->stockOrgWithHolder(1000.0);
            $buyer = $this->user();
            $this->fund($buyer, $currency, '5000');

            $engine = app(ConstitutionalEngine::class);

            $offered = $engine->file('F-IND-021', $seller, [
                'action'          => 'offer_shares',
                'organization_id' => (string) $org->id,
                'units'           => 200,
                'price_per_unit'  => '3.000000',
            ]);

            $engine->file('F-IND-021', $buyer, [
                'action'   => 'buy_shares',
                'offer_id' => $offered->recorded['offer_id'],
            ]);

            $this->assertEqualsWithDelta(200.0, $this->held($org->id, $buyer->id), 0.0001, 'the engine door moved the units');
        });
    }

    /** A holding backs a SUM of offers — over-listing across offers is refused. */
    public function test_offering_more_across_two_offers_than_held_is_refused(): void
    {
        $this->onLivePg(function () {
            [$org, $seller] = $this->stockOrgWithHolder(100.0);
            $trades = app(ShareTradeService::class);

            $trades->offer($seller, (string) $org->id, 60.0, '1.000000'); // ok
            $this->expectException(ConstitutionalViolation::class);
            $trades->offer($seller, (string) $org->id, 60.0, '1.000000'); // 60+60 > 100 → refused
        });
    }

    /** Selling 100% ends the seller's owner-class membership (no phantom voter). */
    public function test_selling_all_ends_the_seller_owner_membership(): void
    {
        $this->onLivePg(function () {
            [$org, $seller, $currency] = $this->stockOrgWithHolder(100.0);
            $buyer = $this->user();
            $this->fund($buyer, $currency, '5000');

            $trades = app(ShareTradeService::class);
            $offer = $trades->offer($seller, (string) $org->id, 100.0, '2.000000');
            $trades->buy($buyer, $offer['offer_id']);

            $class = $org->membershipKind();
            $sellerActive = DB::table('org_memberships')
                ->where('organization_id', $org->id)->where('user_id', $seller->id)
                ->where('kind', $class)->where('status', 'active')->exists();
            $this->assertFalse($sellerActive, 'a fully-divested seller keeps no owner membership');

            $this->assertEqualsWithDelta(0.0, $this->held($org->id, $seller->id), 0.0001);
            $this->assertEqualsWithDelta(100.0, $this->held($org->id, $buyer->id), 0.0001);
        });
    }

    /** A restructure to non-stock between offer and buy refuses the settlement. */
    public function test_buy_refuses_if_org_restructured_to_non_stock(): void
    {
        $this->onLivePg(function () {
            [$org, $seller, $currency] = $this->stockOrgWithHolder(100.0);
            $buyer = $this->user();
            $this->fund($buyer, $currency, '5000');

            $trades = app(ShareTradeService::class);
            $offer = $trades->offer($seller, (string) $org->id, 50.0, '1.000000');

            // The org restructures away from stock before the buy settles.
            DB::table('organizations')->where('id', $org->id)->update(['structure' => 'member_owned']);

            $this->expectException(ConstitutionalViolation::class);
            $trades->buy($buyer, $offer['offer_id']);
        });
    }

    /** A filled offer cannot be cancelled (status-guarded, race-safe). */
    public function test_a_filled_offer_cannot_be_cancelled(): void
    {
        $this->onLivePg(function () {
            [$org, $seller, $currency] = $this->stockOrgWithHolder(100.0);
            $buyer = $this->user();
            $this->fund($buyer, $currency, '5000');

            $trades = app(ShareTradeService::class);
            $offer = $trades->offer($seller, (string) $org->id, 40.0, '1.000000');
            $trades->buy($buyer, $offer['offer_id']);

            try {
                $trades->cancel($seller, $offer['offer_id']);
                $this->fail('a filled offer must not be cancellable');
            } catch (\RuntimeException) {
                // expected — only an open offer can be withdrawn
            }

            $this->assertSame('filled', (string) DB::table('share_offers')->where('id', $offer['offer_id'])->value('status'));
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{0: Organization, 1: User, 2: Currency} */
    private function stockOrgWithHolder(float $units): array
    {
        $root = DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->value('id');
        if ($root === null) {
            $this->markTestSkipped('no root jurisdiction on this box');
        }

        $currency = Currency::query()->where('jurisdiction_id', $root)->whereNull('deleted_at')->first();
        if ($currency === null) {
            $this->markTestSkipped('no root currency on this box');
        }

        $seller = $this->user();
        $orgId = (string) Str::uuid();
        DB::table('organizations')->insert([
            'id'              => $orgId,
            'jurisdiction_id' => $root,
            'type'            => 'business',
            'name'            => 'Trade Co ' . substr($orgId, 0, 8),
            'slug'            => 'trade-' . substr($orgId, 0, 8),
            'structure'       => 'stock',
            'agent_user_id'   => (string) $seller->id,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $org = Organization::query()->findOrFail($orgId);
        app(OrgOwnershipService::class)->openStake($org, OrgOwnershipStake::HOLDER_USERS, (string) $seller->id, $units, OrgOwnershipStake::VIA_FOUNDING);

        return [$org, $seller, $currency];
    }

    private function fund(User $user, Currency $currency, string $amount): void
    {
        $account = app(AccountService::class)->open('users', (string) $user->id, (string) $currency->id);
        DB::table('economic_accounts')->where('id', $account->id)->update(['balance' => $amount]);
    }

    private function held(string $orgId, string $holderId): float
    {
        return (float) OrgOwnershipStake::query()
            ->where('organization_id', $orgId)
            ->where('holder_type', OrgOwnershipStake::HOLDER_USERS)
            ->where('holder_id', $holderId)
            ->open()
            ->sum('units');
    }

    private function user(): User
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id'                => $id,
            'name'              => 'Trade ' . substr($id, 0, 8),
            'email'             => "trade-{$id}@test.invalid",
            'password'          => 'x',
            'terms_accepted_at' => now(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            $conn->rollBack();
            DB::setDefaultConnection($original);
        }
    }
}
