<?php

namespace App\Services\Judiciary;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Judiciary;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Services\ChamberVoteService;
use App\Services\ConstituentResolver;

/**
 * Phase E judiciary acts (PHASE_E_DESIGN_judiciary §B) — the proposal →
 * chamber-vote → adoption-effect loop for F-LEG-017 (judiciary creation,
 * supermajority `judiciary_create`) and F-LEG-018 (judiciary conversion to
 * elected, supermajority `judiciary_convert` + constituent dual leg).
 *
 * Sibling of ExecutiveActService (same pattern; kept separate so the
 * Phase C/D classes do not grow unboundedly — the §B.110 25-line
 * duplication ruling). The vote engine's `chamber_vote_proposal` votable
 * dispatch routes proposals of JUD_KINDS to ChamberActService::
 * applyProposalAdoption, which dispatches the JUD_KINDS effects to
 * JudiciaryFormationService (the EXEC_KINDS precedent).
 */
class JudiciaryActService
{
    /** Proposal kinds this service resolves (the dispatch router key). */
    public const JUD_KINDS = [
        ChamberVoteProposal::KIND_JUDICIARY_CREATION,
        ChamberVoteProposal::KIND_JUDICIARY_CONVERSION,
        ChamberVoteProposal::KIND_JUDICIARY_DISSOLUTION,
    ];

    /**
     * Per-constituent chamber threshold for the F-LEG-018 dual leg:
     * ORDINARY MAJORITY of all serving (`procedural_motion` class) — the
     * constitution states the supermajority ACROSS jurisdictions; the
     * per-chamber threshold is unstated → the owner ruling. REUSED verbatim
     * from the executive design (ExecutiveActService::
     * CONSTITUENT_CONSENT_VOTE_TYPE). Pinned by the conversion test.
     */
    public const CONSTITUENT_CONSENT_VOTE_TYPE = 'procedural_motion';

    public function __construct(
        private readonly ChamberVoteService $votes,
    ) {}

    // =========================================================================
    // Proposals
    // =========================================================================

    /**
     * F-LEG-017 — Judiciary Creation Act (supermajority `judiciary_create`).
     * The DEFAULT and ONLY output is an APPOINTED court (Art. IV §1 — elected
     * courts come ONLY via F-LEG-018 conversion). The nomination mode is
     * DERIVED from the jurisdiction's constituent structure, never an input.
     *
     * Payload: court_name, function_text, judges_per_constituent? (≥ 1, the
     * constituent path's equal count), committee_judge_count? (the committee
     * path's count, ≥ min_judges).
     */
    public function proposeCreation(
        Legislature $legislature,
        LegislatureMember $proposer,
        string $courtName,
        string $functionText,
        ?int $judgesPerConstituent,
        ?int $committeeJudgeCount,
    ): array {
        $judiciary = $this->judiciaryOf($legislature, 'F-LEG-017');

        if ($judiciary->status !== Judiciary::STATUS_FORMING) {
            throw new ConstitutionalViolation(
                "The jurisdiction's judiciary is not awaiting creation (status: {$judiciary->status}).",
                'Art. IV §1'
            );
        }

        if (trim($functionText) === '') {
            throw new ConstitutionalViolation(
                'A judiciary creation act states the court\'s function explicitly (the charter text).',
                'Art. IV §1'
            );
        }

        // Pure shape asserts (DB-free): the seat-pool floor + the derived
        // mode's count, given whether THIS jurisdiction has constituents.
        $hasConstituents = ConstituentResolver::ids($legislature) !== [];

        JudiciaryFormationService::assertCreationShape(
            hasConstituents: $hasConstituents,
            minJudges: (int) $judiciary->min_judges,
            judgesPerConstituent: $judgesPerConstituent,
            committeeJudgeCount: $committeeJudgeCount,
        );

        return $this->propose(
            $legislature,
            $proposer,
            ChamberVoteProposal::KIND_JUDICIARY_CREATION,
            [
                'judiciary_id' => (string) $judiciary->id,
                'court_name' => $courtName !== '' ? $courtName : $judiciary->court_name,
                'function_text' => $functionText,
                'judges_per_constituent' => $judgesPerConstituent,
                'committee_judge_count' => $committeeJudgeCount,
            ],
            'judiciary_create',
        );
    }

    /**
     * F-LEG-018 — Judiciary Conversion Act (supermajority `judiciary_convert`,
     * dual constituent-supermajority leg). Converts an existing APPOINTED
     * court to an ELECTED one. Payload: judge_count (≥
     * judiciary_min_judges_per_race — the elected-race floor, Art. IV §1),
     * charter_text.
     */
    public function proposeConversion(
        Legislature $legislature,
        LegislatureMember $proposer,
        int $judgeCount,
        string $charterText,
    ): array {
        $judiciary = $this->judiciaryOf($legislature, 'F-LEG-018');

        if ($judiciary->status !== Judiciary::STATUS_APPOINTED) {
            throw new ConstitutionalViolation(
                "Conversion applies to an APPOINTED court (status: {$judiciary->status}).",
                'Art. IV §3'
            );
        }

        JudiciaryFormationService::assertConversionTarget($judgeCount, (int) $judiciary->min_judges);

        if (trim($charterText) === '') {
            throw new ConstitutionalViolation(
                'A judiciary conversion act carries the charter text of the elected court.',
                'Art. IV §3'
            );
        }

        return $this->propose(
            $legislature,
            $proposer,
            ChamberVoteProposal::KIND_JUDICIARY_CONVERSION,
            [
                'judiciary_id' => (string) $judiciary->id,
                'judge_count' => $judgeCount,
                'charter_text' => $charterText,
            ],
            'judiciary_convert',
        );
    }

    // =========================================================================
    // Adoption effects ride ChamberActService::applyProposalAdoption (the
    // single chamber_vote_proposal resolution path — the EXEC_KINDS
    // precedent), which dispatches JUD_KINDS to JudiciaryFormationService.
    // =========================================================================

    // =========================================================================
    // Internals (the ExecutiveActService propose() pattern)
    // =========================================================================

    private function propose(
        Legislature $legislature,
        LegislatureMember $proposer,
        string $kind,
        array $payload,
        string $voteType,
    ): array {
        $proposal = ChamberVoteProposal::create([
            'legislature_id' => $legislature->id,
            'proposal_kind' => $kind,
            'payload' => $payload,
            'proposed_by_member_id' => $proposer->id,
            'status' => ChamberVoteProposal::STATUS_OPEN,
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

    /** The ONE judiciary of the legislature's jurisdiction (ESM-18). */
    private function judiciaryOf(Legislature $legislature, string $formId): Judiciary
    {
        $judiciary = Judiciary::query()
            ->where('jurisdiction_id', $legislature->jurisdiction_id)
            ->first();

        if ($judiciary === null) {
            throw new ConstitutionalViolation(
                "{$formId}: no judiciary row exists for this jurisdiction — the setup wizard "
                .'scaffolds one per legislature.',
                'Art. IV §1'
            );
        }

        return $judiciary;
    }
}
