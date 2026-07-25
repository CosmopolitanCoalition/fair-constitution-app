<?php

namespace App\Services\Economy;

use App\Models\Economy\Currency;
use App\Services\SettingsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * PROTECTED — Phase L slice L-6. The civic stipend run (F-TRE-004).
 *
 * ELIGIBILITY IS THE HARDENED PART: an ACTIVE RESIDENCY ASSOCIATION and
 * nothing else. The same absolute gate as voting (Art. I) — no age, no
 * balance, no badge, no fee, no length-of-stay, no good standing. If a
 * condition is ever added here it is unconstitutional, and StipendRunTest
 * pins that zeroing or disabling the stipend changes no R-## eligibility.
 *
 * The amount is [POLICY] and entirely the player base's:
 *
 *     amount = civic_stipend_floor
 *            + min( Σ pay_* for the recipient's active roles , stipend_bump_cap )
 *
 * Operator rulings 2026-07-25: the stipend is ALWAYS ON for all players
 * (stipend_enabled defaults true), the bump classes are the three shipped
 * dials — every node operator, every social moderator, and EVERY civic
 * role-holder, with no role subset — and funding is a founder dial.
 *
 * FUNDING, both paths:
 *   minted        → IssuanceService mints the run total (Art. V §5, root only)
 *   treasury_draw → the run debits a designated treasury account, and if that
 *                   account cannot cover the run it is PRO-RATA SHORT-PAID
 *                   and published as short-paid. Never a silent skip and
 *                   never a partial population: everyone is paid the same
 *                   fraction, because paying the first N residents in id
 *                   order and stopping would be an arbitrary distinction
 *                   between people the constitution treats identically.
 *
 * PRIVACY: one PUBLIC aggregate row (recipients, total) plus per-recipient
 * receipts that are account-scoped, so they inherit the M-1 posture with no
 * special case. Per-class aggregates are k-anon suppressed by the caller —
 * in a jurisdiction with one office-holder, "the office-holder bump went to
 * the office-holder" is inferable from a published class total.
 */
class StipendService
{
    /** Below this many recipients in a class, its aggregate is suppressed. */
    public const K_ANON_FLOOR = 5;

    public function __construct(
        private LedgerService $ledger,
        private IssuanceService $issuance,
        private AccountService $accounts,
        private SettingsResolver $settings,
    ) {}

    /**
     * Run the stipend for a jurisdiction.
     *
     * @param  array<int, array{account_id:string, roles:array<int,string>}>  $recipients
     *         resolved by the caller from ACTIVE RESIDENCY ONLY
     * @return array{disbursement_id:string, recipients:int, total:string, short_paid:bool}
     */
    public function run(
        string $jurisdictionId,
        Currency $currency,
        array $recipients,
        string $treasuryAccountId,
    ): array {
        if (! $this->enabled($jurisdictionId)) {
            throw new RuntimeException('The civic stipend is disabled for this jurisdiction.');
        }

        $floor = (string) $this->settings->resolveInt($jurisdictionId, 'civic_stipend_floor', 50);
        $cap   = (string) $this->settings->resolveInt($jurisdictionId, 'stipend_bump_cap', 20);
        $bumps = [
            'node_operator'    => (string) $this->settings->resolveInt($jurisdictionId, 'pay_node_operator', 8),
            'social_moderator' => (string) $this->settings->resolveInt($jurisdictionId, 'pay_social_moderator', 5),
            'office_holder'    => (string) $this->settings->resolveInt($jurisdictionId, 'pay_office_holder', 12),
        ];

        // 1. What everyone is owed, before funding is considered.
        $lines = [];
        $gross = '0';
        foreach ($recipients as $r) {
            $bump = $this->bumpFor($r['roles'] ?? [], $bumps, $cap);
            $amount = bcadd($floor, $bump, 6);

            $lines[] = ['account_id' => $r['account_id'], 'base' => $floor, 'bump' => $bump, 'amount' => $amount];
            $gross = bcadd($gross, $amount, 6);
        }

        if ($lines === []) {
            throw new RuntimeException('No eligible recipients — a stipend run with nobody to pay is not a run.');
        }

        // 2. Funding, and the short-pay ratio if the treasury cannot cover it.
        $source = $this->settings->resolve($jurisdictionId, 'stipend_funding_source') ?? 'minted';
        $ratio  = '1';
        $shortPaid = false;

        if ($source === 'treasury_draw') {
            $available = (string) (DB::table('treasury_accounts')->where('id', $treasuryAccountId)->value('balance') ?? '0');

            if (bccomp($available, $gross, 6) === -1) {
                $shortPaid = true;
                $ratio = bccomp($gross, '0', 6) === 1 ? bcdiv($available, $gross, 6) : '0';
            }
        }

        return DB::transaction(function () use (
            $jurisdictionId, $currency, $lines, $gross, $source, $ratio, $shortPaid, $treasuryAccountId
        ) {
            // 3. Apply the ratio and compute what is actually paid.
            $net = '0';
            foreach ($lines as $i => $line) {
                $paid = $shortPaid ? bcmul($line['amount'], $ratio, 6) : $line['amount'];
                $lines[$i]['paid'] = $paid;
                $net = bcadd($net, $paid, 6);
            }

            // 4. Fund the run.
            if ($source === 'minted') {
                $this->issuance->mint($currency, $treasuryAccountId, $net, 'civic stipend run');
            }

            $disbursementId = (string) Str::uuid();
            DB::table('ubi_disbursements')->insert([
                'id'              => $disbursementId,
                'jurisdiction_id' => $jurisdictionId,
                'currency_id'     => $currency->id,
                'ran_at'          => now(),
                'recipients'      => count($lines),
                'total'           => $net,
                'funding_source'  => $source,
                'short_paid'      => $shortPaid,
                'short_pay_ratio' => $shortPaid ? $ratio : null,
                'created_at'      => now(),
            ]);

            // 5. Pay each recipient from the treasury; write the private line.
            foreach ($lines as $line) {
                if (bccomp($line['paid'], '0', 6) !== 1) {
                    continue;
                }

                $this->accounts->creditFromTreasury(
                    $treasuryAccountId,
                    $line['account_id'],
                    $currency->id,
                    $line['paid'],
                    'stipend',
                );

                DB::table('ubi_receipts')->insert([
                    'id'              => (string) Str::uuid(),
                    'disbursement_id' => $disbursementId,
                    'account_id'      => $line['account_id'],
                    'base'            => $line['base'],
                    'bump'            => $line['bump'],
                    'amount'          => $line['paid'],
                    'created_at'      => now(),
                ]);
            }

            return [
                'disbursement_id' => $disbursementId,
                'recipients'      => count($lines),
                'total'           => $net,
                'short_paid'      => $shortPaid,
            ];
        });
    }

    /**
     * The role differential, capped at the SUM. Every civic role-holder
     * qualifies (operator ruling) — the three shipped dials are the class
     * set, so there is deliberately no per-role table to drift out of date.
     *
     * @param  array<int, string>  $roles
     * @param  array<string, string>  $bumps
     */
    public function bumpFor(array $roles, array $bumps, string $cap): string
    {
        $total = '0';

        foreach (array_unique($roles) as $role) {
            $total = bcadd($total, $bumps[$role] ?? '0', 6);
        }

        return bccomp($total, $cap, 6) === 1 ? $cap : $total;
    }

    public function enabled(string $jurisdictionId): bool
    {
        $value = $this->settings->resolve($jurisdictionId, 'stipend_enabled');

        // Default TRUE (operator ruling: always on for all players). A
        // legislature switches it off by act; an unset value is not "off".
        return $value === null ? true : (bool) $value;
    }

    /**
     * k-anonymity: a class with fewer than K_ANON_FLOOR recipients in a
     * jurisdiction is folded into the general total rather than published,
     * because a small eligible set makes the private receipt inferable from
     * the public aggregate.
     */
    public function suppressSmallClasses(array $classTotals): array
    {
        $out = ['general' => '0'];

        foreach ($classTotals as $class => $row) {
            if (($row['recipients'] ?? 0) < self::K_ANON_FLOOR) {
                $out['general'] = bcadd($out['general'], (string) ($row['total'] ?? '0'), 6);
                continue;
            }

            $out[$class] = (string) $row['total'];
        }

        return $out;
    }
}
