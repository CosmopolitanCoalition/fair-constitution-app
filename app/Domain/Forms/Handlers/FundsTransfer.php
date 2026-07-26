<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Economy\Currency;
use App\Models\User;
use App\Services\Economy\AccountService;

/**
 * F-IND-023 — Funds Transfer (R-01). Art. I — economic freedom, free
 * movement of capital.
 *
 * A DOOR ON A BUILT SERVICE, NOT A SHORTCUT. Everything goes through
 * AccountService::transfer, which posts a balanced pair of ledger entries
 * through LedgerService — the same path `institutions:demo-treasury` drives.
 * Nothing here writes a balance, a ledger row or a transaction directly.
 *
 * Consequences that follow from using the real service rather than
 * reimplementing it, and which are the reason to route through it:
 *   - Σdebits = Σcredits, enforced per currency, per posting.
 *   - No overdraft. The individual economy has no credit facility —
 *     borrowing is a jurisdiction instrument (Art. V §4), not a wallet
 *     feature — so an insufficient balance REFUSES rather than going
 *     negative.
 *   - The ledger stays append-only and hash-chained.
 *
 * PRIVACY (operator ruling: reader privacy, like a ballot). The filer names
 * a RECIPIENT ACCOUNT, never a person. The sender's own account is resolved
 * from their identity here — the one place that lookup is lawful — so a
 * transfer form can never be used to discover who owns an account.
 */
class FundsTransfer implements FormHandler
{
    public function __construct(
        private readonly AccountService $accounts,
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
