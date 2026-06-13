<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Models\CaseFiling;
use App\Models\User;
use App\Services\Judiciary\CaseFilingService;

/**
 * F-ADV-004 — Brief / Argument Filing. Actor R-21. Appends a `case_filings`
 * row filing_kind='brief'; the attach-window allows briefs until
 * `deliberation` — a brief filed after deliberation opens is rejected by the
 * attach-window gate (the advocate-console "no filings accepted" gate).
 */
class BriefFiling implements FormHandler
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
        return 'brief.filed';
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
        return $this->docketAdvocateFiling($actor, $payload, CaseFiling::KIND_BRIEF, 'F-ADV-004');
    }
}
