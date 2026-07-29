<?php

namespace App\Services\Economy;

use Illuminate\Support\Facades\DB;

/**
 * Design Round 2 ④ — currency-distribution telemetry, ACCOUNT-CLEAN.
 *
 * The operator's seed asks for "currency-distribution telemetry [that lets]
 * tax structures auto-manage inflation within bounds," and flagged the
 * auto-management CONTROLLER as its own future round. This service is the
 * telemetry that every option needs first — and ONLY the telemetry. It reads
 * and it recommends nothing; no rate moves here. A rate still moves only by a
 * dual-door act (F-LEG-031 / F-LEG-037).
 *
 * READER-PRIVACY, the hard rail: every figure aggregates over ACCOUNTS, never
 * over people. This service NEVER touches economic_account_bindings, never
 * reads a user row, never resolves an account to an owner. A spread over
 * account balances is a spread over accounts — it says nothing about who. A
 * source-scan test pins that this file names no identity table.
 *
 * PER-JURISDICTION holdings are deliberately absent: economic_accounts carry
 * no jurisdiction link, and the only path to one crosses the binding wall. A
 * geographic distribution map is out of scope until a privacy-safe
 * aggregation is designed. A supply TIME-SERIES is likewise absent — it needs
 * a snapshot table (a migration), and this round ships the instantaneous read.
 */
class CurrencyTelemetryService
{
    public function __construct(private IssuanceService $issuance) {}

    /**
     * The instantaneous distribution snapshot for one currency. Every money
     * value is a string (numeric(24,6)); ratios are strings too.
     *
     * @return array{
     *   supply: string, in_circulation: string, treasury_held: string,
     *   wallets: int, funded_wallets: int, top_decile_share_pct: string|null,
     *   velocity_30d: string|null, per_jurisdiction: null, time_series: null
     * }
     */
    public function snapshot(string $currencyId): array
    {
        $supply = $this->issuance->supply($currencyId);

        // Private wallets — the money-plane accounts. account rows only.
        $wallets = DB::table('economic_accounts')
            ->where('currency_id', $currencyId)
            ->whereNull('deleted_at');

        $walletCount   = (int) (clone $wallets)->count();
        $fundedCount   = (int) (clone $wallets)->where('balance', '>', 0)->count();
        $inCirculation = $this->money((clone $wallets)->sum('balance'));

        // Public money, by construction (Art. III §4) — treasury accounts.
        $treasuryHeld = $this->money(
            DB::table('treasury_accounts')->where('currency_id', $currencyId)->whereNull('deleted_at')->sum('balance')
        );

        return [
            'supply'               => $supply,
            'in_circulation'       => $inCirculation,
            'treasury_held'        => $treasuryHeld,
            'wallets'              => $walletCount,
            'funded_wallets'       => $fundedCount,
            // Concentration: the share of circulating money held by the top
            // tenth of funded wallets. A spread over accounts, never people.
            'top_decile_share_pct' => $this->topDecileSharePct($currencyId, $fundedCount, $inCirculation),
            // Velocity: money moved in the last 30 days over the supply — how
            // many times the stock turned over. Null when nothing has issued.
            'velocity_30d'         => $this->velocity30d($currencyId, $supply),
            // Honest absences (see the class docblock).
            'per_jurisdiction'     => null,
            'time_series'          => null,
        ];
    }

    private function topDecileSharePct(string $currencyId, int $fundedCount, string $inCirculation): ?string
    {
        if ($fundedCount === 0 || bccomp($inCirculation, '0', 6) <= 0) {
            return null;
        }

        $topN = (int) max(1, (int) ceil($fundedCount * 0.1));

        $topSum = $this->money(
            DB::table('economic_accounts')
                ->where('currency_id', $currencyId)
                ->whereNull('deleted_at')
                ->where('balance', '>', 0)
                ->orderByDesc('balance')
                ->limit($topN)
                ->pluck('balance')
                ->reduce(fn ($carry, $b) => bcadd((string) $carry, (string) $b, 6), '0')
        );

        // (topSum / inCirculation) * 100, two decimals.
        return bcmul(bcdiv($topSum, $inCirculation, 8), '100', 2);
    }

    private function velocity30d(string $currencyId, string $supply): ?string
    {
        if (bccomp($supply, '0', 6) <= 0) {
            return null;
        }

        $volume = $this->money(
            DB::table('market_transactions')
                ->where('currency_id', $currencyId)
                ->where('created_at', '>=', now()->subDays(30))
                ->sum('amount')
        );

        return bcdiv($volume, $supply, 4);
    }

    private function money(mixed $value): string
    {
        return $value === null ? '0.000000' : bcadd((string) $value, '0', 6);
    }
}
