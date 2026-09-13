<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\CaseFiling;
use App\Models\User;
use App\Services\Judiciary\CaseFilingService;
use App\Services\Judiciary\CaseService;

/**
 * F-JDG-013 — Dismissal Order (operator ruling 2026-09-13; case-lifecycle-controls-shape A).
 *
 * Actor R-19/R-20 (a SEATED judge of THIS court). Ends a case that is not
 * justiciable or is withdrawn: filed/accepted → dismissed (terminal) through
 * CaseService::dismiss, which publishes the dismissal record. A stated reason
 * is mandatory. A dismissal after the window (a paneled or later case) is
 * refused by the legal ESM edge — the state machine IS the repeated-submission
 * and out-of-window check.
 */
class DismissalOrder implements FormHandler
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
        return 'case.dismissed';
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
        $case = JudicialActor::case($payload, 'F-JDG-013');
        JudicialActor::seat($actor, (string) $case->judiciary_id, 'F-JDG-013');

        $reason = trim((string) ($payload['reason'] ?? ''));

        if ($reason === '') {
            throw new ConstitutionalViolation(
                'A dismissal order states its reason — the public record names why the case ended.',
                'Art. IV §4'
            );
        }

        $this->cases->dismiss($case, $reason);

        $this->filings->docket($case->refresh(), [
            'filing_form' => 'F-JDG-013',
            'filing_kind' => CaseFiling::KIND_ORDER,
            'filed_by_user_id' => (string) $actor->getKey(),
            'filed_by_role' => 'R-19',
            'title' => 'Dismissal order',
            'body' => $reason,
            'enforce_attach_window' => false,
        ]);

        return [
            'case_id' => (string) $case->id,
            'status' => (string) $case->refresh()->status,
            'reason' => $reason,
        ];
    }
}
