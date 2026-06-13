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
 * F-ADV-001 — Case Filing (on behalf of client). The advocate variant of
 * F-IND-017: actor R-21, ALWAYS on-behalf-of a client; the retainer note is
 * recorded with the filing. The double-jeopardy bar runs at the validator
 * stage exactly as for F-IND-017.
 */
class AdvocateCaseFiling implements FormHandler
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
        return ['R-21'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation('F-ADV-001 is filed by a registered advocate.', 'Art. IV §4');
        }

        if (! isset($payload['filed_on_behalf_of_user_id'])) {
            throw new ConstitutionalViolation(
                'F-ADV-001 is filed ON BEHALF OF a client — name filed_on_behalf_of_user_id.',
                'Art. IV §4'
            );
        }

        $advocate = JudicialActor::advocate($actor, (string) ($payload['judiciary_id'] ?? ''), 'F-ADV-001');

        return $this->openCase($actor, $payload, $advocate, 'F-ADV-001');
    }
}
