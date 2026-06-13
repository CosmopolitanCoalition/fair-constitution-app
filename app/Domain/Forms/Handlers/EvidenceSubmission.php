<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Models\CaseFiling;
use App\Models\User;
use App\Services\Judiciary\CaseFilingService;

/**
 * F-ADV-003 — Evidence Submission. Actor R-21. Appends a `case_filings` row
 * filing_kind='evidence' onto the open evidence docket; the panel rules on
 * admissibility (admitted/excluded + written reasons) as a follow-up filing.
 */
class EvidenceSubmission implements FormHandler
{
    use Concerns\DocketsAdvocateFilings;

    public function __construct(
        private readonly CaseFilingService $filings,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'evidence.filed';
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
        return $this->docketAdvocateFiling($actor, $payload, CaseFiling::KIND_EVIDENCE, 'F-ADV-003');
    }
}
