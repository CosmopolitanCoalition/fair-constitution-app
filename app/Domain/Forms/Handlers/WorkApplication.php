<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Economy\Currency;
use App\Models\User;
use App\Services\Economy\AccountService;
use App\Services\Economy\LaborBoardService;
use Illuminate\Support\Facades\DB;

/**
 * F-IND-019 — Work Application (R-01). Art. I freedom to contract.
 *
 * Applying is the market-surface half of the labor board: it commits nobody
 * to anything and touches no civic right — there is no means test and no
 * qualification gate. The CONSTITUTIONAL half is the hire: acceptance files
 * F-IND-014 (worker registration → org_contracts → countersign →
 * co-determination), which is the applicant's own separate act through the
 * already-pinned chain. This form cannot reach any of that machinery.
 *
 * A DOOR ON A BUILT SERVICE: everything routes through
 * LaborBoardService::apply(), and the applicant is resolved to their OWN
 * account — the form takes no person and no account as input, so it cannot
 * apply on anyone else's behalf.
 */
class WorkApplication implements FormHandler
{
    public function __construct(
        private readonly LaborBoardService $board,
        private readonly AccountService $accounts,
    ) {
    }

    public function module(): string
    {
        return 'economy';
    }

    public function event(): string
    {
        return 'labor.applied';
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
                'Applying for work is done by a person — system filing is not defined.',
                'CGA Forms Catalog (F-IND-019)'
            );
        }

        $postingId = (string) ($payload['posting_id'] ?? '');

        if ($postingId === '') {
            throw new ConstitutionalViolation('An application names the posting.', 'CGA Forms Catalog (F-IND-019)');
        }

        $currency = $this->currency();
        $accountId = $this->accounts->accountIdFor('users', (string) $actor->id, $currency->id);

        if ($accountId === null) {
            throw new ConstitutionalViolation(
                'You have no wallet in this currency yet — one opens with confirmed residency.',
                'Art. I · as implemented'
            );
        }

        try {
            $applicationId = $this->board->apply(
                $postingId,
                $accountId,
                isset($payload['note']) && trim((string) $payload['note']) !== ''
                    ? trim((string) $payload['note'])
                    : null,
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // The service's refusals ARE the constitutional answer — a
            // closed posting, a duplicate application. Surface them with a
            // citation rather than as a 500.
            throw new ConstitutionalViolation($e->getMessage(), 'Art. I · as implemented');
        }

        return [
            'action'               => 'applied',
            'application_id'       => $applicationId,
            'posting_id'           => $postingId,
            'applicant_account_id' => $accountId,
        ];
    }

    private function currency(): Currency
    {
        $rootId = DB::table('jurisdictions')
            ->whereNull('parent_id')->whereNull('deleted_at')->value('id');

        $currency = $rootId === null
            ? null
            : Currency::query()->where('jurisdiction_id', $rootId)->whereNull('deleted_at')->first();

        if ($currency === null) {
            throw new ConstitutionalViolation(
                'This world has no currency yet — the root jurisdiction defines one (Art. V §5).',
                'Art. V §5'
            );
        }

        return $currency;
    }
}
