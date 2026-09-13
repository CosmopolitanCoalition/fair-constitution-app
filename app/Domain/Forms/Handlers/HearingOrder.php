<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\CaseFiling;
use App\Models\User;
use App\Services\Judiciary\CaseFilingService;
use App\Services\Judiciary\CaseService;

/**
 * F-JDG-011 — Hearing Order (operator ruling 2026-09-13; case-lifecycle-controls-shape A).
 *
 * Actor R-19/R-20 (a SEATED judge of THIS court). Opens arguments: the case
 * advances paneled/jury_empaneled → heard through CaseService::advanceToHearing.
 * The legal ESM edge is the repeated-submission check — a second hearing order
 * (heard → heard) is not in the transition table and CaseService refuses it.
 * The court instrument is docketed for the public record (Art. IV §4).
 */
class HearingOrder implements FormHandler
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
        return 'case.hearing_ordered';
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
        $case = JudicialActor::case($payload, 'F-JDG-011');
        JudicialActor::seat($actor, (string) $case->judiciary_id, 'F-JDG-011');

        $this->cases->advanceToHearing($case);

        $this->filings->docket($case->refresh(), [
            'filing_form' => 'F-JDG-011',
            'filing_kind' => CaseFiling::KIND_ORDER,
            'filed_by_user_id' => (string) $actor->getKey(),
            'filed_by_role' => 'R-19',
            'title' => 'Hearing order — arguments open',
            'enforce_attach_window' => false,
        ]);

        return [
            'case_id' => (string) $case->id,
            'status' => (string) $case->refresh()->status,
        ];
    }
}
