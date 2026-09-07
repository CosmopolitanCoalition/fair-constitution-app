<?php

namespace App\Services\Education;

use App\Models\Economy\Currency;
use App\Models\User;
use App\Services\Economy\AccountService;
use App\Services\Economy\IssuanceService;
use App\Services\SettingsResolver;
use Illuminate\Support\Facades\DB;

/**
 * The ONE-TIME training stipend (K2_ENGINE_PLAN §5.0.1 — operator ruling 6
 * ties completion to "the one-time civic stipend for finishing a training").
 *
 * FOLLOWS THE StipendService POSTURE WITHOUT TOUCHING IT: amount from an
 * amendable, jurisdiction-scoped setting (`training_stipend_amount`,
 * resolver-inherited, default at this call site); funding source resolving
 * the same way (`stipend_funding_source`, minted default); the money moving
 * treasury → wallet through the same ledger legs the sweep uses. Engine-
 * carried: the only caller is the F-EDU-001 handler, inside the engine's
 * transaction — doors, never shortcuts.
 *
 * ONCE-ONLY IS NOT THIS CLASS'S JOB. The caller pays iff the ACH-EDU-001
 * achievement row was NEWLY minted — the append-only ledger IS the proof
 * (idempotent on user + award key), and there is deliberately no second
 * bookkeeping here.
 *
 * THE MONEY IS DECORATION; THE COMPLETION IS THE RECORD. Every missing
 * prerequisite — no confirmed residency, no currency, no wallet, no
 * treasury, a zero amount, an underfunded treasury draw — SKIPS the payout
 * rather than failing the filing. Education is the right (§5.0.2); a broken
 * or unbuilt economy must never block training. Genuine DB failures still
 * propagate and roll the whole filing back — chain consistency outranks the
 * decoration.
 *
 * READER PRIVACY (lane 13's accounts-never-people rail): the payout writes
 * ledger legs and a private wallet credit only. Nothing here touches the
 * audit payload — the public chain records the completion, never the money.
 */
class TrainingStipendService
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly IssuanceService $issuance,
        private readonly SettingsResolver $settings,
    ) {
    }

    /**
     * BATCH MODE (2026-09-07). The mass pre-train pass pays one training stipend
     * per newly-trained holder, and each inline payOnce is a mint + a
     * creditFromTreasury — two posts on the ledger's ONE global advisory lock,
     * so a big chamber serialized every lane through that lock (the training
     * pole, ~8.4 s an item on Germany). Between beginBatch() and commitBatch()
     * the MINTED-funded stipends are BUFFERED (a static, shared across the
     * handler's and the caller's instances, like AuditService's batch) and paid
     * as ONE mint + one creditManyFromTreasury per treasury/currency. The
     * once-per-person rule is unchanged: payOnce is still only reached when the
     * achievement was newly minted, so the buffer holds one entry a person.
     * Treasury-draw stipends (the affordability check is per person) still pay
     * inline — the sim funds from minting, so the batch covers its hot path.
     */
    private static ?array $batch = null;

    public function beginBatch(): void
    {
        self::$batch = [];
    }

    /** Drop the buffer without paying — tests roll back the accounts it points at. */
    public static function resetBatch(): void
    {
        self::$batch = null;
    }

    /** Pay the one-time stipend if every prerequisite exists; skip silently otherwise. */
    public function payOnce(User $learner): void
    {
        $jurisdictionId = DB::table('residency_confirmations')
            ->where('user_id', (string) $learner->getKey())
            ->where('is_active', true)
            ->orderByDesc('confirmed_at')
            ->value('jurisdiction_id');

        if ($jurisdictionId === null) {
            return; // trainable without residency; just not payable yet
        }

        $amount = $this->settings->resolveInt(
            (string) $jurisdictionId,
            'training_stipend_amount',
            (int) config('cga.education.training_stipend_default', 10),
        );

        if ($amount <= 0) {
            return; // a lawful zero — a legislature may set it so
        }

        $rootId = DB::table('jurisdictions')
            ->whereNull('parent_id')->whereNull('deleted_at')->value('id');

        $currency = $rootId === null
            ? null
            : Currency::query()->where('jurisdiction_id', $rootId)->whereNull('deleted_at')->first();

        if ($currency === null) {
            return; // no economy in this world yet
        }

        $walletId = $this->accounts->accountIdFor('users', (string) $learner->getKey(), $currency->id);

        if ($walletId === null) {
            return; // no wallet yet — opens with confirmed residency
        }

        $treasuryId = DB::table('treasury_accounts')
            ->where('owner_type', 'jurisdictions')
            ->where('owner_id', $rootId)
            ->where('currency_id', $currency->id)
            ->whereNull('deleted_at')
            ->value('id');

        if ($treasuryId === null) {
            return; // no treasury to pay from
        }

        $source = $this->settings->resolve((string) $jurisdictionId, 'stipend_funding_source') ?? 'minted';
        $paid = (string) $amount;

        // BATCH the minted hot path: buffer this credit, deferring the ledger
        // posts to commitBatch (one mint + one creditManyFromTreasury a
        // treasury). Same money, same once-per-person gate, one lock hold.
        if (self::$batch !== null && $source === 'minted') {
            self::$batch[] = [
                'treasury_id' => (string) $treasuryId,
                'currency_id' => (string) $currency->id,
                'currency'    => $currency,
                'wallet_id'   => (string) $walletId,
                'amount'      => $paid,
            ];

            return;
        }

        if ($source === 'minted') {
            $this->issuance->mint($currency, (string) $treasuryId, $paid, 'training stipend (F-EDU-001, once per person)');
        } else {
            $available = (string) (DB::table('treasury_accounts')->where('id', $treasuryId)->value('balance') ?? '0');

            if (bccomp($available, $paid, 6) === -1) {
                return; // an underfunded draw skips — never a partial for one person
            }
        }

        $this->accounts->creditFromTreasury((string) $treasuryId, $walletId, $currency->id, $paid, 'stipend');
    }

    /**
     * Pay the buffered minted stipends: one mint of the total and one
     * creditManyFromTreasury per (treasury, currency). Ends the batch. The
     * per-person amounts are unchanged — only the number of ledger posts drops,
     * from two a holder to two a treasury.
     */
    public function commitBatch(): void
    {
        $batch = self::$batch ?? [];
        self::$batch = null;

        if ($batch === []) {
            return;
        }

        $groups = [];
        foreach ($batch as $row) {
            $g = $row['treasury_id'].'|'.$row['currency_id'];
            $groups[$g] ??= [
                'treasury_id' => $row['treasury_id'],
                'currency_id' => $row['currency_id'],
                'currency'    => $row['currency'],
                'total'       => '0',
                'credits'     => [],
            ];
            $groups[$g]['total'] = bcadd($groups[$g]['total'], $row['amount'], 6);
            $groups[$g]['credits'][] = ['account_id' => $row['wallet_id'], 'amount' => $row['amount']];
        }

        foreach ($groups as $g) {
            $this->issuance->mint($g['currency'], $g['treasury_id'], $g['total'], 'training stipend batch (F-EDU-001, once per person)');
            $this->accounts->creditManyFromTreasury($g['treasury_id'], $g['credits'], $g['currency_id'], 'stipend');
        }
    }
}
