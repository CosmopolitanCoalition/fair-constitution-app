<?php

namespace App\Services\Executive;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Executive;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Services\ChamberVoteService;
use Illuminate\Support\Facades\DB;

/**
 * Phase D executive acts (PHASE_D_DESIGN_executive §B/§C/§E.1) — the
 * proposal → chamber-vote → adoption-effect loop for F-LEG-014 (executive
 * delegation, supermajority `exec_delegate`), F-LEG-015 (executive office
 * creation/conversion, supermajority `exec_office_create` + constituent
 * dual leg), and F-LEG-016 (department creation, ordinary majority
 * `procedural_motion` — unstated-threshold owner ruling, F-LEG-013
 * precedent).
 *
 * Sibling of ChamberActService (same pattern; kept separate so the
 * Phase C class does not grow unboundedly — design §E.1). The vote
 * engine's `chamber_vote_proposal` votable dispatch routes proposals of
 * EXEC_KINDS here; adoption effects live in ExecutiveFormationService /
 * DepartmentService.
 */
class ExecutiveActService
{
    /** Proposal kinds this service resolves (the dispatch router key). */
    public const EXEC_KINDS = [
        ChamberVoteProposal::KIND_EXEC_DELEGATION,
        ChamberVoteProposal::KIND_EXEC_CONVERSION,
        ChamberVoteProposal::KIND_DEPARTMENT_CREATION,
    ];

    /**
     * Per-constituent chamber threshold for the F-LEG-015 dual leg:
     * ORDINARY MAJORITY of all serving (`procedural_motion` class) — the
     * constitution states the supermajority ACROSS jurisdictions; the
     * per-chamber threshold is unstated → MANIFEST §8 owner ruling.
     * Pinned by ExecConversionDualSupermajorityTest.
     */
    public const CONSTITUENT_CONSENT_VOTE_TYPE = 'procedural_motion';

    /**
     * Governor-removal vote class (owner ruling #14): ordinary-majority
     * hiring-and-firing — deliberately NOT `officeholder_remove`. Pinned
     * by GovernorRemovalOrdinaryMajorityTest.
     */
    public const GOVERNOR_REMOVAL_VOTE_TYPE = 'procedural_motion';

    public function __construct(
        private readonly ChamberVoteService $votes,
    ) {
    }

    // =========================================================================
    // Proposals
    // =========================================================================

    /**
     * F-LEG-014 — Executive Committee Delegation Act (supermajority,
     * `exec_delegate`). Payload: delegated scope text + member count
     * (Art. III §2: ≥ 5) + optional interest declarations.
     *
     * @param  list<string>  $interestMemberIds  member ids declaring interest
     */
    public function proposeDelegation(
        Legislature $legislature,
        LegislatureMember $proposer,
        string $delegatedScope,
        int $memberCount,
        array $interestMemberIds = [],
    ): array {
        $executive = $this->executiveOf($legislature, 'F-LEG-014');

        if ($executive->status !== Executive::STATUS_FORMING) {
            throw new ConstitutionalViolation(
                "The jurisdiction's executive is not awaiting delegation (status: {$executive->status}).",
                'Art. III §1'
            );
        }

        if (trim($delegatedScope) === '') {
            throw new ConstitutionalViolation(
                'A delegation act states the delegated scope explicitly — it is the order-scope '
                . 'validation input (F-EXE-005).',
                'Art. III §2'
            );
        }

        $serving = $this->servingCount($legislature);

        ExecutiveFormationService::assertDelegationSize($memberCount, $serving);

        return $this->propose(
            $legislature,
            $proposer,
            ChamberVoteProposal::KIND_EXEC_DELEGATION,
            [
                'executive_id'    => (string) $executive->id,
                'delegated_scope' => $delegatedScope,
                'member_count'    => $memberCount,
                'interest'        => array_values(array_map('strval', $interestMemberIds)),
            ],
            'exec_delegate',
        );
    }

    /**
     * F-LEG-015 — Executive Office Creation/Conversion Act
     * (supermajority `exec_office_create`, dual constituent-supermajority
     * leg). Payload: target type + member count (committee ⇒ ≥ 5,
     * Art. III §3) + the charter text of the elected office.
     */
    public function proposeConversion(
        Legislature $legislature,
        LegislatureMember $proposer,
        string $targetType,
        ?int $memberCount,
        string $charterText,
    ): array {
        $executive = $this->executiveOf($legislature, 'F-LEG-015');

        if (! in_array($executive->status, [Executive::STATUS_FORMING, Executive::STATUS_DELEGATED], true)) {
            throw new ConstitutionalViolation(
                "The executive cannot convert from status [{$executive->status}].",
                'Art. III §3'
            );
        }

        ExecutiveFormationService::assertConversionTarget($targetType, $memberCount);

        if (trim($charterText) === '') {
            throw new ConstitutionalViolation(
                'An office creation/conversion act carries the charter text of the elected office.',
                'Art. III §3'
            );
        }

        return $this->propose(
            $legislature,
            $proposer,
            ChamberVoteProposal::KIND_EXEC_CONVERSION,
            [
                'executive_id' => (string) $executive->id,
                'target_type'  => $targetType,
                'member_count' => $targetType === Executive::TYPE_COMMITTEE ? (int) $memberCount : 1,
                'charter_text' => $charterText,
            ],
            'exec_office_create',
        );
    }

    /**
     * F-LEG-016 — Department Creation Act (ordinary majority —
     * `procedural_motion`, the unstated-threshold class; precedent
     * F-LEG-013). Payload per design §C.1.
     */
    public function proposeDepartmentCreation(
        Legislature $legislature,
        LegislatureMember $proposer,
        array $payload,
    ): array {
        $payload = DepartmentService::validateCreationPayload($legislature, $payload);

        // Nominee eligibility = active association only (Art. I — the
        // F-LEG-012 posture; neutrality is a duty of office).
        foreach ($payload['nominees'] as $userId) {
            $this->assertNomineeAssociation($userId, (string) $legislature->jurisdiction_id, 'F-LEG-016');
        }

        return $this->propose(
            $legislature,
            $proposer,
            ChamberVoteProposal::KIND_DEPARTMENT_CREATION,
            $payload,
            'procedural_motion',
        );
    }

    // =========================================================================
    // Adoption effects ride ChamberActService::applyProposalAdoption (the
    // single chamber_vote_proposal resolution path — the orgs-scope
    // precedent), which dispatches EXEC_KINDS to
    // ExecutiveFormationService / DepartmentService.
    // =========================================================================

    // =========================================================================
    // Internals (the ChamberActService propose() pattern — design §E.1
    // allows the 25-line duplication over coupling the two services)
    // =========================================================================

    private function propose(
        Legislature $legislature,
        LegislatureMember $proposer,
        string $kind,
        array $payload,
        string $voteType,
    ): array {
        $proposal = ChamberVoteProposal::create([
            'legislature_id'        => $legislature->id,
            'proposal_kind'         => $kind,
            'payload'               => $payload,
            'proposed_by_member_id' => $proposer->id,
            'status'                => ChamberVoteProposal::STATUS_OPEN,
        ]);

        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: $voteType,
            votable: $proposal,
            stage: ChamberVote::STAGE_FLOOR,
            opener: $proposer,
        );

        $proposal->forceFill(['vote_id' => (string) $vote->id])->save();

        return ['proposal_id' => (string) $proposal->id, 'vote_id' => (string) $vote->id];
    }

    /** The ONE executive of the legislature's jurisdiction (ESM-16). */
    private function executiveOf(Legislature $legislature, string $formId): Executive
    {
        $executive = Executive::query()
            ->where('jurisdiction_id', $legislature->jurisdiction_id)
            ->first();

        if ($executive === null) {
            throw new ConstitutionalViolation(
                "{$formId}: no executive row exists for this jurisdiction — the setup wizard "
                . 'scaffolds one per legislature.',
                'Art. III §1'
            );
        }

        return $executive;
    }

    private function servingCount(Legislature $legislature): int
    {
        return LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->count();
    }

    private function assertNomineeAssociation(string $userId, string $jurisdictionId, string $formId): void
    {
        $associated = DB::table('residency_confirmations')
            ->where('user_id', $userId)
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->exists();

        if (! $associated) {
            throw new ConstitutionalViolation(
                "{$formId} nominee [{$userId}] holds no active association with the jurisdiction — "
                . 'association is the only eligibility check (Art. I).',
                'Art. I'
            );
        }
    }
}
