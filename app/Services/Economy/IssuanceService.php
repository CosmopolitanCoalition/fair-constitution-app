<?php

namespace App\Services\Economy;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Economy\Currency;
use App\Models\Jurisdiction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PROTECTED — Phase L slice L-3. Where money lawfully begins and ends.
 *
 * Art. V §5 reserves currency PRODUCTION to the most encompassing
 * jurisdiction. Minting is therefore the single lawful exception to the
 * ledger's conservation rule, and it is deliberately isolated here rather
 * than exposed on LedgerService: a credit with no matching debit is money
 * coming into existence, and that may only happen where the constitution says
 * it may. Every other service in the economy can only move value that already
 * exists.
 *
 * Every mint and burn writes an append-only issuance_event before its ledger
 * posting, so the money supply is a public record independent of the ledger
 * that spends it. The two can be reconciled: Σmints − Σburns must equal the
 * ledger's imbalance for that currency, which is exactly what
 * LedgerIntegrityTest checks.
 *
 * The app takes NO position on how much money should exist. issuance_rate_bps
 * and inflation_target_bps are dual-door levers a legislature turns on the
 * public record (slice L-1); this service is the machinery, not the policy.
 */
class IssuanceService
{
    public const MINT = 'mint';
    public const BURN = 'burn';

    public function __construct(private LedgerService $ledger) {}

    /**
     * Mint into a treasury account. Root-issued currency only.
     *
     * @param  string  $reason  goes on the public record beside the amount
     */
    public function mint(Currency $currency, string $toTreasuryAccountId, string $amount, string $reason, ?string $actId = null): string
    {
        return $this->emit($currency, self::MINT, $toTreasuryAccountId, $amount, $reason, $actId);
    }

    /** Burn out of a treasury account — the contraction side of the lever. */
    public function burn(Currency $currency, string $fromTreasuryAccountId, string $amount, string $reason, ?string $actId = null): string
    {
        return $this->emit($currency, self::BURN, $fromTreasuryAccountId, $amount, $reason, $actId);
    }

    private function emit(Currency $currency, string $direction, string $accountId, string $amount, string $reason, ?string $actId): string
    {
        $this->assertIssuable($currency);

        if (bccomp($amount, '0', 6) !== 1) {
            throw new \InvalidArgumentException('An issuance must be greater than zero.');
        }

        return DB::transaction(function () use ($currency, $direction, $accountId, $amount, $reason, $actId) {
            // The unbalanced leg — the ONLY place allowUnbalanced is true.
            // Mint credits value in; burn debits it out.
            $entryGroup = $this->ledger->post(
                'issuance',
                [[
                    'account_type' => 'treasury_accounts',
                    'account_id'   => $accountId,
                    'currency_id'  => $currency->id,
                    'direction'    => $direction === self::MINT
                        ? LedgerService::DIRECTION_CREDIT
                        : LedgerService::DIRECTION_DEBIT,
                    'amount'       => $amount,
                ]],
                'issuance_events',
                null,
                allowUnbalanced: true,
            );

            DB::table('issuance_events')->insert([
                'id'          => (string) Str::uuid(),
                'currency_id' => $currency->id,
                'direction'   => $direction,
                'amount'      => $amount,
                'reason'      => $reason,
                'act_id'      => $actId,
                'entry_group' => $entryGroup,
                'created_at'  => now(),
            ]);

            return $entryGroup;
        });
    }

    /**
     * Art. V §5 — a currency issued by anything but the root jurisdiction is
     * unconstitutional, so minting one is too. Checked here as well as at
     * definition time: a currency row could in principle be reparented by a
     * later migration, and the mint path must not inherit that mistake.
     */
    public function assertIssuable(Currency $currency): void
    {
        $issuer = Jurisdiction::query()->find($currency->jurisdiction_id);

        if ($issuer === null || $issuer->parent_id !== null) {
            throw new ConstitutionalViolation(
                sprintf('[%s] is not issued by the most encompassing Jurisdiction and may not be minted.', $currency->code),
                'Art. V §5'
            );
        }
    }

    /** Σmints − Σburns for a currency: the money this instance has created. */
    public function supply(string $currencyId): string
    {
        $row = DB::selectOne(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'mint' THEN amount ELSE -amount END), 0) AS supply
             FROM issuance_events WHERE currency_id = ?",
            [$currencyId]
        );

        return (string) ($row->supply ?? '0');
    }
}
