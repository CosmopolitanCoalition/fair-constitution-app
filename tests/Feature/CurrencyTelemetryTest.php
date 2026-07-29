<?php

namespace Tests\Feature;

use App\Services\Economy\CurrencyTelemetryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Design Round 2 ④ — currency-distribution telemetry, account-clean.
 *
 * The snapshot aggregates over accounts and never people: it computes supply,
 * circulation, wallet counts, a top-decile concentration and a 30-day
 * velocity, and it is honest about what it does NOT have (per-jurisdiction
 * holdings, a supply time-series). The reader-privacy pin is a source scan:
 * the service must never query the binding or users tables.
 */
class CurrencyTelemetryTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'telemetry_pg';

    public function test_the_snapshot_aggregates_over_accounts(): void
    {
        $this->onLivePg(function () {
            $currencyId = $this->currency();

            // Four wallets: 700 / 200 / 100 / 0. Circulation 1000, 3 funded.
            foreach (['700', '200', '100', '0'] as $balance) {
                $this->wallet($currencyId, $balance);
            }

            // Supply 1000, from a single mint.
            $this->mint($currencyId, '1000');

            // 500 moved in the last 30 days → velocity 0.5 of the stock.
            $this->transfer($currencyId, '500');

            $snap = app(CurrencyTelemetryService::class)->snapshot($currencyId);

            $this->assertSame(0, bccomp($snap['supply'], '1000', 6), 'supply = mint − burn');
            $this->assertSame(0, bccomp($snap['in_circulation'], '1000', 6));
            $this->assertSame(4, $snap['wallets']);
            $this->assertSame(3, $snap['funded_wallets']);
            // top tenth of 3 funded wallets = 1 wallet (the 700), 70% of 1000.
            $this->assertSame('70.00', $snap['top_decile_share_pct']);
            $this->assertSame('0.5000', $snap['velocity_30d']);

            // Honest absences — these are null by design, not omitted.
            $this->assertNull($snap['per_jurisdiction']);
            $this->assertNull($snap['time_series']);
        });
    }

    public function test_an_empty_currency_is_honest_not_a_divide_by_zero(): void
    {
        $this->onLivePg(function () {
            $currencyId = $this->currency();

            $snap = app(CurrencyTelemetryService::class)->snapshot($currencyId);

            $this->assertSame(0, $snap['wallets']);
            $this->assertNull($snap['top_decile_share_pct'], 'no funded wallets → no concentration, not a crash');
            $this->assertNull($snap['velocity_30d'], 'no supply → no velocity');
        });
    }

    /**
     * The reader-privacy rail, pinned as a source scan. The service may name
     * the binding/users tables in PROSE (the docblock explains what it does
     * NOT touch); it may never QUERY them. So the scan targets the query
     * construct table('…') / join('…'), not the bare word.
     */
    public function test_the_service_never_queries_an_identity_table(): void
    {
        $src = file_get_contents(app_path('Services/Economy/CurrencyTelemetryService.php'));

        $this->assertStringNotContainsString("table('economic_account_bindings'", $src);
        $this->assertStringNotContainsString("table('users'", $src);
        $this->assertStringNotContainsString("join('users", $src);
        $this->assertStringNotContainsString('user_id', $src);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function currency(): string
    {
        $root = DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->value('id');
        if ($root === null) {
            $this->markTestSkipped('no root jurisdiction on this box');
        }

        $id = (string) Str::uuid();
        DB::table('currencies')->insert([
            'id'              => $id,
            'jurisdiction_id' => $root,
            'name'            => 'Telemetry Unit',
            'code'            => 'T' . strtoupper(substr($id, 0, 3)),
            'symbol'          => 'ŧ',
            'precision'       => 6,
            'unit_kind'       => 'abstract',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return $id;
    }

    private function wallet(string $currencyId, string $balance): void
    {
        DB::table('economic_accounts')->insert([
            'id'          => (string) Str::uuid(),
            'kind'        => 'user',
            'currency_id' => $currencyId,
            'balance'     => $balance,
            'status'      => 'open',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function mint(string $currencyId, string $amount): void
    {
        DB::table('issuance_events')->insert([
            'id'          => (string) Str::uuid(),
            'currency_id' => $currencyId,
            'direction'   => 'mint',
            'amount'      => $amount,
            'reason'      => 'telemetry fixture',
            'created_at'  => now(),
        ]);
    }

    private function transfer(string $currencyId, string $amount): void
    {
        DB::table('market_transactions')->insert([
            'id'          => (string) Str::uuid(),
            'currency_id' => $currencyId,
            'amount'      => $amount,
            'kind'        => 'transfer',
            'created_at'  => now(),
        ]);
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
