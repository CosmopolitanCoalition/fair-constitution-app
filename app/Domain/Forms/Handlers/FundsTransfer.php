<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Economy\Currency;
use App\Models\User;
use App\Services\Economy\AccountService;
use App\Services\Economy\JointLedgerService;

/**
 * F-IND-023 — Funds Transfer · Joint-Ledger Movement (R-01). Art. I —
 * economic freedom, free movement of capital; Art. V §2 — the shared-
 * resource basis of joint control. One form, both — exactly the pairing
 * ECONOMY_ENGINE_PLAN's manifest names, because a joint movement IS a
 * transfer whose consent takes more than one signature.
 *
 * A DOOR ON A BUILT SERVICE, NOT A SHORTCUT. Plain transfers go through
 * AccountService::transfer, which posts a balanced pair of ledger entries
 * through LedgerService — the same path `institutions:demo-treasury` drives.
 * Joint actions go through JointLedgerService, whose settlements are that
 * SAME AccountService transfer from the ledger's escrow account. Nothing
 * here writes a balance, a ledger row or a transaction directly.
 *
 * Consequences that follow from using the real service rather than
 * reimplementing it, and which are the reason to route through it:
 *   - Σdebits = Σcredits, enforced per currency, per posting.
 *   - No overdraft. The individual economy has no credit facility —
 *     borrowing is a jurisdiction instrument (Art. V §4), not a wallet
 *     feature — so an insufficient balance REFUSES rather than going
 *     negative. An underfunded joint escrow refuses identically.
 *   - The ledger stays append-only and hash-chained.
 *
 * PRIVACY (operator ruling: reader privacy, like a ballot). The filer names
 * a RECIPIENT ACCOUNT, never a person — joint parties included. The filer's
 * own account is resolved from their identity here — the one place that
 * lookup is lawful — so no action on this form can be used to discover who
 * owns an account.
 */
class FundsTransfer implements FormHandler
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly JointLedgerService $joint,
    ) {
    }

    public function module(): string
    {
        return 'economy';
    }

    public function event(): string
    {
        return 'funds.transferred';
    }

    public function requiredRoles(): array
    {
        return ['R-01'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'A transfer is made by a person — system filing is not defined.',
                'CGA Forms Catalog (F-IND-023)'
            );
        }

        $action = (string) ($payload['action'] ?? 'transfer');

        if ($action !== 'transfer') {
            return $this->jointAction($actor, $action, $payload);
        }

        $toAccountId = (string) ($payload['to_account_id'] ?? '');
        $amount      = (string) ($payload['amount'] ?? '');

        if ($toAccountId === '') {
            throw new ConstitutionalViolation('F-IND-023 names the recipient account.', 'CGA Forms Catalog (F-IND-023)');
        }

        if ($amount === '' || bccomp($amount, '0', 6) !== 1) {
            throw new ConstitutionalViolation(
                'A transfer moves a positive amount.',
                'CGA Forms Catalog (F-IND-023)'
            );
        }

        $currency = $this->resolveCurrency($payload);

        $fromAccountId = $this->accounts->accountIdFor('users', (string) $actor->id, $currency->id);

        if ($fromAccountId === null) {
            throw new ConstitutionalViolation(
                'You have no wallet in this currency yet — a wallet opens with confirmed residency.',
                'Art. I · as implemented'
            );
        }

        // The service refuses an overdraft and an account paying itself.
        // Both surface to the filer as a constitutional rejection rather
        // than a 500, because a refusal IS the constitutional answer.
        try {
            $entryGroup = $this->accounts->transfer(
                $fromAccountId,
                $toAccountId,
                $currency->id,
                $amount,
                'transfer',
                isset($payload['memo']) ? (string) $payload['memo'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            throw new ConstitutionalViolation($e->getMessage(), 'Art. I · as implemented');
        }

        return [
            'entry_group'     => $entryGroup,
            'from_account_id' => $fromAccountId,
            'to_account_id'   => $toAccountId,
            'currency_id'     => (string) $currency->id,
            'amount'          => $amount,
        ];
    }

    /**
     * The joint-ledger half of the form: open a co-owned ledger, propose a
     * movement out of one, approve a pending movement. The filer acts as
     * their OWN account in every case — a party list may include others'
     * accounts (that is what co-ownership is), but only as accounts.
     */
    private function jointAction(User $actor, string $action, array $payload): array
    {
        $currency = $this->resolveCurrency($payload);

        $accountId = $this->accounts->accountIdFor('users', (string) $actor->id, $currency->id);

        if ($accountId === null) {
            throw new ConstitutionalViolation(
                'You have no wallet in this currency yet — a wallet opens with confirmed residency.',
                'Art. I · as implemented'
            );
        }

        try {
            return match ($action) {
                'joint_open' => $this->jointOpen($accountId, $currency, $payload),
                'joint_propose' => ['action' => 'joint_proposed'] + $this->joint->propose(
                    (string) ($payload['ledger_id'] ?? ''),
                    $accountId,
                    (string) ($payload['to_account_id'] ?? ''),
                    (string) ($payload['amount'] ?? ''),
                    isset($payload['memo']) && trim((string) $payload['memo']) !== '' ? trim((string) $payload['memo']) : null,
                ),
                'joint_approve' => ['action' => 'joint_approved'] + $this->joint->approve(
                    (string) ($payload['movement_id'] ?? ''),
                    $accountId,
                ),
                default => throw new ConstitutionalViolation(
                    "Unknown action [{$action}] — transfer, joint_open, joint_propose or joint_approve.",
                    'CGA Forms Catalog (F-IND-023)'
                ),
            };
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // The service's refusals ARE the constitutional answer — a
            // non-party acting, a double approval, an underfunded escrow.
            throw new ConstitutionalViolation($e->getMessage(), 'Art. I · as implemented');
        }
    }

    /** @return array<string, mixed> */
    private function jointOpen(string $accountId, Currency $currency, array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            throw new ConstitutionalViolation('A joint ledger needs a name.', 'CGA Forms Catalog (F-IND-023)');
        }

        // The opener is ALWAYS a party — you cannot open a joint ledger you
        // stand outside of; the other co-owners arrive as account ids.
        $parties = array_values(array_unique(array_merge(
            [$accountId],
            array_map('strval', (array) ($payload['party_account_ids'] ?? [])),
        )));

        $opened = $this->joint->open(
            $name,
            isset($payload['purpose']) && trim((string) $payload['purpose']) !== '' ? trim((string) $payload['purpose']) : null,
            (string) $currency->id,
            $parties,
            (string) ($payload['approval_rule'] ?? 'all'),
            (bool) ($payload['public'] ?? false),
        );

        return ['action' => 'joint_opened'] + $opened;
    }

    private function resolveCurrency(array $payload): Currency
    {
        if (isset($payload['currency_id'])) {
            $currency = Currency::query()->find((string) $payload['currency_id']);
        } else {
            // The root's currency — Art. V §5 reserves issuance to the most
            // encompassing jurisdiction, so a world has one by construction.
            $rootId = \Illuminate\Support\Facades\DB::table('jurisdictions')
                ->whereNull('parent_id')->whereNull('deleted_at')->value('id');

            $currency = $rootId === null
                ? null
                : Currency::query()->where('jurisdiction_id', $rootId)->whereNull('deleted_at')->first();
        }

        if ($currency === null) {
            throw new ConstitutionalViolation(
                'This world has no currency yet — the root jurisdiction defines one (Art. V §5).',
                'Art. V §5'
            );
        }

        return $currency;
    }
}
