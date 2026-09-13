<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\CaseFiling;
use App\Models\User;
use App\Services\Judiciary\CaseFilingService;
use App\Services\Judiciary\CaseService;

/**
 * F-JDG-012 — Deliberation Order (operator ruling 2026-09-13; case-lifecycle-controls-shape A).
 *
 * Actor R-19/R-20 (a SEATED judge of THIS court). Closes arguments and opens
 * the chambers / jury room: heard → deliberation through
 * CaseService::enterDeliberation. Deliberation is the only unrecorded space;
 * the order that opens it is the recorded court instrument. A second order
 * (deliberation → deliberation) is refused by the legal ESM edge — the state
 * machine IS the repeated-submission check.
 */
class DeliberationOrder implements FormHandler
{
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
        return 'case.deliberation_ordered';
    }

    public function requiredRoles(): array
    {
        return ['R-19', 'R-20'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $case = JudicialActor::case($payload, 'F-JDG-012');
        JudicialActor::seat($actor, (string) $case->judiciary_id, 'F-JDG-012');

        $this->cases->enterDeliberation($case);

        $this->filings->docket($case->refresh(), [
            'filing_form' => 'F-JDG-012',
            'filing_kind' => CaseFiling::KIND_ORDER,
            'filed_by_user_id' => (string) $actor->getKey(),
            'filed_by_role' => 'R-19',
            'title' => 'Deliberation order — the case is submitted',
            'enforce_attach_window' => false,
        ]);

        return [
            'case_id' => (string) $case->id,
            'status' => (string) $case->refresh()->status,
        ];
    }
}
