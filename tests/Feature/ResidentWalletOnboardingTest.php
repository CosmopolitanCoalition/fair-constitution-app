<?php

namespace Tests\Feature;

use App\Models\{ResidencyClaim, User};
use App\Models\Economy\Currency;
use App\Services\Economy\{AccountService, ResidentWalletService};
use App\Services\ResidencyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\DisposableRepairWorld;
use Tests\TestCase;

class ResidentWalletOnboardingTest extends TestCase
{
    use DisposableRepairWorld;

    private string $scope;
    private User $resident;
    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openRepairWorld();
        config(['cache.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'array', 'cga.residency_instant' => true]);
        $this->withoutVite();
        $this->scope = (string) Str::uuid();
        DB::table('jurisdictions')->insert(['id' => $this->scope, 'name' => 'Test Earth', 'slug' => 'test-earth',
            'adm_level' => 0, 'population' => 1000, 'created_at' => now(), 'updated_at' => now()]);
        $this->resident = User::factory()->create();
        $this->currency = Currency::create(['jurisdiction_id' => $this->scope, 'name' => 'Test credits', 'code' => 'TST', 'symbol' => 'T']);
    }

    protected function tearDown(): void { $this->closeRepairWorld(); parent::tearDown(); }

    private function confirm(): void
    {
        DB::table('residency_confirmations')->insert(['user_id' => $this->resident->id, 'jurisdiction_id' => $this->scope,
            'days_confirmed' => 1, 'is_active' => true, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_existing_resident_can_open_once_register_an_item_and_offer_it_without_money(): void
    {
        $this->confirm();
        $this->actingAs($this->resident);
        $this->get('/economy/wallet')->assertOk();
        self::assertSame(0, DB::table('economic_accounts')->count(), 'Reading must not create an account.');
        $this->post('/economy/wallet/open')->assertRedirect('/economy/wallet');
        $account = app(AccountService::class)->accountIdFor('users', $this->resident->id, $this->currency->id);
        self::assertNotNull($account);
        $this->post('/economy/wallet/open')->assertRedirect('/economy/wallet');
        self::assertSame(1, DB::table('economic_accounts')->count());
        self::assertSame('0.000000', DB::table('economic_accounts')->where('id', $account)->value('balance'));
        $this->from('/economy/wallet')->post('/economy/assets', ['name' => 'Handmade test bowl', 'kind' => 'physical', 'quantity' => '1'])
            ->assertRedirect('/economy/wallet')->assertSessionHasNoErrors();
        $asset = DB::table('assets')->where('owner_account_id', $account)->first();
        self::assertNotNull($asset);
        $this->from('/economy/market')->post('/economy/market', ['asset_id' => $asset->id, 'price' => '5', 'title' => 'Test bowl', 'kind' => 'good'])
            ->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame(1, DB::table('marketplace_listings')->where('seller_account_id', $account)->count());
        self::assertSame(0, DB::table('ledger_entries')->count(), 'Provisioning and listing must not mint money.');
    }

    public function test_guests_and_unconfirmed_or_inactive_residents_cannot_open_wallets(): void
    {
        $this->post('/economy/wallet/open')->assertRedirect('/login');
        $this->actingAs($this->resident)->post('/economy/wallet/open')->assertSessionHasErrors('wallet');
        $this->confirm();
        DB::table('residency_confirmations')->where('user_id', $this->resident->id)->update(['is_active' => false]);
        $this->post('/economy/wallet/open')->assertSessionHasErrors('wallet');
        self::assertSame(0, DB::table('economic_accounts')->count());
    }

    public function test_confirmation_opens_the_wallet_in_the_same_transaction_and_rolls_back_together(): void
    {
        $claim = ResidencyClaim::create(['user_id' => $this->resident->id, 'jurisdiction_id' => $this->scope,
            'status' => ResidencyClaim::STATUS_PING_MONITORING, 'declared_at' => now(), 'ping_consent_at' => now()]);
        DB::beginTransaction();
        app(ResidencyService::class)->verify($claim);
        self::assertSame(1, DB::table('economic_accounts')->count());
        self::assertSame(1, DB::table('residency_confirmations')->where('user_id', $this->resident->id)->count());
        DB::rollBack();
        self::assertSame(0, DB::table('economic_accounts')->count());
        self::assertSame(0, DB::table('residency_confirmations')->count());
        app(ResidencyService::class)->verify($claim->fresh());
        self::assertSame(1, DB::table('economic_accounts')->count());
        self::assertSame(0, DB::table('ledger_entries')->count());
    }

    public function test_currency_arriving_after_residency_can_be_recovered_without_reconfirming(): void
    {
        $this->confirm();
        $this->currency->delete();
        self::assertNull(app(ResidentWalletService::class)->ensure($this->resident->id));
        $this->currency->restore();
        self::assertNotNull(app(ResidentWalletService::class)->ensure($this->resident->id));
        self::assertSame(1, DB::table('residency_confirmations')->count());
    }

    public function test_locate_rejects_an_old_session_token_and_accepts_the_current_token(): void
    {
        // Laravel normally bypasses CSRF in tests; explicitly exercise it here.
        $middleware = new class($this->app, $this->app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken {
            protected function runningUnitTests() { return false; }
        };
        $this->app->instance(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $middleware);
        DB::table('jurisdictions')->where('id', $this->scope)->update([
            'geom' => DB::raw("ST_Multi(ST_MakeEnvelope(-10,-10,10,10,4326))"),
        ]);
        $this->actingAs($this->resident)->withSession(['_token' => 'current-session-token']);
        $this->get('/civic/residency')->assertOk()->assertViewHas('page', fn ($page) => $page['props']['defaultThreshold'] === 0);
        $this->postJson('/civic/residency/locate', ['lat' => 1, 'lng' => 1], ['X-CSRF-TOKEN' => 'before-login'])->assertStatus(419);
        $this->postJson('/civic/residency/locate', ['lat' => 1, 'lng' => 1], ['X-CSRF-TOKEN' => 'current-session-token'])
            ->assertOk()->assertJsonPath('found', true)->assertJsonPath('jurisdiction.id', $this->scope);
    }
}
