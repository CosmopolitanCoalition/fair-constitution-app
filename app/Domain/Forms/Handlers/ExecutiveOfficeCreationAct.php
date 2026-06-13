<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\Executive;
use App\Models\Legislature;
use App\Models\MultiJurisdictionVote;
use App\Models\User;
use App\Services\Executive\ExecutiveActService;
use App\Services\Executive\ExecutiveFormationService;

/**
 * F-LEG-015 — Executive Office Creation/Conversion Act (WF-EXE-02/03).
 *
 * Modes:
 *  - `propose` (default): supermajority chamber vote (exec_office_create)
 *    on `{target_type, member_count (committee ≥ 5), charter_text}`; on
 *    adoption the constituent dual-supermajority process opens via
 *    MultiJurisdictionVoteService (the FIRST live consumer) — or, with
 *    no constituents, the executive election schedules immediately
 *    (Art. III §3 "where constituents exist").
 *  - `open_constituent_consent`: any R-09 of a CONSTITUENT chamber moves
 *    its consent to decision — that chamber's own ordinary-majority vote
 *    (votable constituent_consent; built generically for Phase E reuse).
 *  - `alter`: WF-EXE-03 — constituent supermajority ONLY
 *    (exec_office_alter, multi_jurisdiction engine; minimal D surface).
 */
class ExecutiveOfficeCreationAct implements FormHandler
{
    public function __construct(
        private readonly ExecutiveActService $acts,
        private readonly ExecutiveFormationService $formation,
    ) {
    }

    public function module(): string
    {
        return 'executive';
    }

    public function event(): string
    {
        return 'executive.conversion_filed';
    }

    public function requiredRoles(): array
    {
        return ['R-09'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $action = (string) ($payload['action'] ?? 'propose');

        return match ($action) {
            'propose'                  => $this->propose($actor, $payload),
            'open_constituent_consent' => $this->openConstituentConsent($actor, $payload),
            'alter'                    => $this->alter($actor, $payload),
            default => throw new ConstitutionalViolation(
                "Unknown F-LEG-015 action [{$action}].",
                'CGA Forms Catalog (F-LEG-015)'
            ),
        };
    }

    private function propose(?User $actor, array $payload): array
    {
        $legislature = ChamberActor::legislature($payload, 'F-LEG-015');
        $member      = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-015');

        $result = $this->acts->proposeConversion(
            $legislature,
            $member,
            (string) ($payload['target_type'] ?? ''),
            isset($payload['member_count']) ? (int) $payload['member_count'] : null,
            (string) ($payload['charter_text'] ?? ''),
        );

        return [
            'action'         => 'propose',
            'legislature_id' => (string) $legislature->id,
            'proposed_by'    => (string) $member->id,
            'target_type'    => (string) $payload['target_type'],
        ] + $result;
    }

    private function openConstituentConsent(?User $actor, array $payload): array
    {
        $process = MultiJurisdictionVote::query()->find((string) ($payload['process_id'] ?? ''));

        if ($process === null) {
            throw new ConstitutionalViolation(
                'F-LEG-015 consent opening names a live constituent process (process_id).',
                'Art. VII · as implemented'
            );
        }

        $legislature = ChamberActor::legislature($payload, 'F-LEG-015');
        $member      = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-015');

        $vote = $this->formation->openConstituentConsentVote($process, $legislature, $member);

        return [
            'action'          => 'open_constituent_consent',
            'process_id'      => (string) $process->id,
            'legislature_id'  => (string) $legislature->id,
            'consent_vote_id' => (string) $vote->id,
        ];
    }

    private function alter(?User $actor, array $payload): array
    {
        $legislature = ChamberActor::legislature($payload, 'F-LEG-015');
        ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-015');

        $executive = Executive::query()
            ->where('jurisdiction_id', $legislature->jurisdiction_id)
            ->first();

        if ($executive === null) {
            throw new ConstitutionalViolation('No executive exists to alter.', 'Art. III §2');
        }

        $process = $this->formation->openAlteration(
            $legislature,
            $executive,
            (array) ($payload['changes'] ?? []),
        );

        return [
            'action'       => 'alter',
            'executive_id' => (string) $executive->id,
            'process_id'   => (string) $process->id,
        ];
    }
}
