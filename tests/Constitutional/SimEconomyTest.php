<?php

namespace Tests\Constitutional;

use App\Services\Demo\SimEconomyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the sim money plane (W7 item 8).
 *
 * Resident wallets and the civic stipend, through the REAL economy services:
 *   · a wallet is an economic_account bound to a user (accounts-never-people)
 *   · the currency is root-only (Art. V §5), defined once
 *   · eligibility is active residency and nothing else (Art. I) — the stipend's
 *     hardened gate, exercised here per jurisdiction
 *   · per-jurisdiction runs are bounded (THE ETL RULE) — no planet-wide txn
 *
 * If an edit breaks these, fix the edit, not the test.
 */
class SimEconomyTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_sim_econ';

    public function test_wallets_open_idempotently_in_the_root_currency(): void
    {
        $this->onLivePg(function () {
            [$jid, $ids] = $this->world(3);
            $econ = app(SimEconomyService::class);

            $this->assertSame(3, $econ->openWalletsFor($ids));
            $currencyId = (string) $econ->ensureCurrency()['currency']->id;

            $this->assertSame(3, DB::table('economic_account_bindings as b')
                ->join('economic_accounts as a', 'a.id', '=', 'b.account_id')
                ->where('b.owner_type', 'users')->whereIn('b.owner_id', $ids)
                ->where('a.currency_id', $currencyId)->count());

            // Idempotent: opening again mints no second wallet.
            $this->assertSame(3, $econ->openWalletsFor($ids));
            $this->assertSame(3, DB::table('economic_account_bindings')->whereIn('owner_id', $ids)->count());
        });
    }

    public function test_the_stipend_credits_every_resident_with_a_wallet(): void
    {
        $this->onLivePg(function () {
            [$jid, $ids] = $this->world(4);
            $econ = app(SimEconomyService::class);
            $econ->openWalletsFor($ids);

            $result = $econ->runStipendFor($jid);

            $this->assertNotNull($result);
            $this->assertSame(4, $result['recipients'], 'every resident with a wallet is paid');

            // The floor is the universal base (default 50); no seated members
            // here, so no office_holder bump — every balance is the floor.
            $currencyId = (string) $econ->ensureCurrency()['currency']->id;
            $balances = DB::table('economic_account_bindings as b')
                ->join('economic_accounts as a', 'a.id', '=', 'b.account_id')
                ->whereIn('b.owner_id', $ids)->where('a.currency_id', $currencyId)
                ->pluck('a.balance')->map(fn ($b) => (float) $b)->all();

            $this->assertCount(4, $balances);
            foreach ($balances as $bal) {
                $this->assertSame(50.0, $bal, 'each resident receives the stipend floor');
            }

            $this->assertSame(1, DB::table('ubi_disbursements')->where('jurisdiction_id', $jid)->count());
            $this->assertSame(4, DB::table('ubi_receipts as r')
                ->join('ubi_disbursements as d', 'd.id', '=', 'r.disbursement_id')
                ->where('d.jurisdiction_id', $jid)->count());
        });
    }

    public function test_a_jurisdiction_with_no_residents_is_a_clean_skip(): void
    {
        $this->onLivePg(function () {
            [$jid] = $this->world(0);

            $this->assertNull(app(SimEconomyService::class)->runStipendFor($jid),
                'a stipend run with nobody to pay returns null, not an error');
        });
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /**
     * A root jurisdiction (currency issuer, Art. V §5) with N sim residents.
     *
     * @return array{0:string, 1:list<string>} [jurisdictionId, userIds]
     */
    private function world(int $residents): array
    {
        $jid = (string) Str::uuid();
        DB::table('jurisdictions')->insert([
            'id' => $jid, 'name' => 'Econ Root', 'slug' => 'econ-root-'.Str::lower(Str::random(10)),
            'adm_level' => 0, 'parent_id' => null, 'population' => 100000, 'source' => 'user_defined',
            'official_languages' => '["en"]', 'timezone' => 'UTC',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ids = [];
        for ($i = 0; $i < $residents; $i++) {
            $uid = (string) Str::uuid();
            $ids[] = $uid;
            DB::table('users')->insert([
                'id' => $uid, 'name' => "Resident {$i}",
                'email' => 'sim-'.Str::lower(Str::random(12))."-{$i}@demo.invalid",
                'password' => bcrypt(Str::random(20)), 'status' => 'registered',
                'terms_accepted_at' => now(), 'timezone' => 'UTC',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('residency_confirmations')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $uid, 'jurisdiction_id' => $jid,
                'days_confirmed' => 30, 'confirmed_at' => now(),
                'voting_right_active' => true, 'candidacy_right_active' => true,
                'is_active' => true, 'depth' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$jid, $ids];
    }

    private function onLivePg(callable $body): void
    {
        SimEconomyService::resetCache();
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            SimEconomyService::resetCache();
        }
    }
}
