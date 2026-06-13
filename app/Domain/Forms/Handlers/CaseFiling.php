<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\OpensCases;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\User;
use App\Services\Judiciary\CaseFilingService;
use App\Services\Judiciary\CaseService;

/**
 * F-IND-017 — Civil/Criminal Case Filing (WF-JUD-03, the case-docket).
 *
 * Actor R-03 (self) OR R-21 (advocate, sets advocate_id /
 * filed_on_behalf_of_user_id). Creates the `cases` row `status='filed'`,
 * `case_parties`, and the opening `case_filings` docket row; the docket_no is
 * allocated; everything publishes. Standing is association-only (Art. I).
 *
 * The double-jeopardy bar runs at the VALIDATOR stage (Art. II §8): a criminal
 * re-filing against the same accused for the same act is rejected pre-commit
 * with a rejected=true chain row — no second `cases` row is created.
 */
class CaseFiling implements FormHandler
{
    use OpensCases;

    public function __construct(
        private readonly CaseService $cases,
        private readonly CaseFilingService $filings,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'case.filed';
    }

    public function requiredRoles(): array
    {
        return ['R-03', 'R-21'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation('F-IND-017 is filed by the complaining resident or their advocate.', 'Art. I');
        }

        // The advocate path: an R-21 filing names a client (on-behalf-of). When
        // present, resolve + attach the advocate row at the case's court.
        $advocate = null;

        if (isset($payload['filed_on_behalf_of_user_id'])) {
            $advocate = JudicialActor::advocate($actor, (string) ($payload['judiciary_id'] ?? ''), 'F-IND-017');
        }

        return $this->openCase($actor, $payload, $advocate, 'F-IND-017');
    }
}
