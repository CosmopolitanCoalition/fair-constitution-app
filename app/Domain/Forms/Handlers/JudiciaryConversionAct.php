<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\MultiJurisdictionVote;
use App\Models\User;
use App\Services\Executive\ExecutiveFormationService;
use App\Services\Judiciary\JudiciaryActService;

/**
 * F-LEG-018 — Judiciary Conversion Act (WF-JUD-02), DUAL supermajority.
 *
 * Mirrors the executive F-LEG-015 conversion EXACTLY. Modes:
 *  - `propose` (default): supermajority chamber vote (judiciary_convert) on
 *    {judge_count ≥ min_judges, charter_text}; on adoption the constituent
 *    dual-supermajority process opens via MultiJurisdictionVoteService — or,
 *    with no constituents, the judicial election schedules immediately
 *    (Art. IV §3 "if composed of constituent jurisdictions").
 *  - `open_constituent_consent`: any R-09 of a CONSTITUENT chamber moves its
 *    consent to decision — that chamber's own ordinary-majority vote
 *    (votable constituent_consent; the GENERIC arm built for this reuse,
 *    routed through ExecutiveFormationService::openConstituentConsentVote).
 */
class JudiciaryConversionAct implements FormHandler
{
    public function __construct(
        private readonly JudiciaryActService $acts,
        private readonly ExecutiveFormationService $constituentConsent,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'judiciary.conversion_filed';
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
            'propose' => $this->propose($actor, $payload),
            'open_constituent_consent' => $this->openConstituentConsent($actor, $payload),
            default => throw new ConstitutionalViolation(
                "Unknown F-LEG-018 action [{$action}].",
                'CGA Forms Catalog (F-LEG-018)'
            ),
        };
    }

    private function propose(?User $actor, array $payload): array
    {
        $legislature = ChamberActor::legislature($payload, 'F-LEG-018');
        $member = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-018');

        $result = $this->acts->proposeConversion(
            $legislature,
            $member,
            (int) ($payload['judge_count'] ?? 0),
            (string) ($payload['charter_text'] ?? ''),
        );

        return [
            'action' => 'propose',
            'legislature_id' => (string) $legislature->id,
            'proposed_by' => (string) $member->id,
        ] + $result;
    }

    private function openConstituentConsent(?User $actor, array $payload): array
    {
        $process = MultiJurisdictionVote::query()->find((string) ($payload['process_id'] ?? ''));

        if ($process === null) {
            throw new ConstitutionalViolation(
                'F-LEG-018 consent opening names a live constituent process (process_id).',
                'Art. VII · as implemented'
            );
        }

        $legislature = ChamberActor::legislature($payload, 'F-LEG-018');
        $member = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-018');

        // The constituent-consent vote machinery is GENERIC (built for this
        // reuse — the votable arm routes back to the judiciary effect via
        // resolveConstituentConsentVote's subject_type switch).
        $vote = $this->constituentConsent->openConstituentConsentVote($process, $legislature, $member);

        return [
            'action' => 'open_constituent_consent',
            'process_id' => (string) $process->id,
            'legislature_id' => (string) $legislature->id,
            'consent_vote_id' => (string) $vote->id,
        ];
    }
}
