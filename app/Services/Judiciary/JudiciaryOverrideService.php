<?php

namespace App\Services\Judiciary;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\ConstitutionalChallenge;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\RemedyRecommendation;
use App\Services\ChamberVoteService;
use Illuminate\Support\Facades\DB;

/**
 * JudiciaryOverrideService (PHASE_E_DESIGN_challenge_law §B.5) — Path 2: the
 * legislature's SUPERMAJORITY override of a constitutional finding (F-LEG-035,
 * §5.4 "A Supermajority of The Legislature may disagree with the Judiciary and
 * overrule its judgement within a set Judicial veto window").
 *
 * Sibling of ChamberActService: the proposal → chamber-vote → resolution loop
 * for the override, riding the `chamber_vote_proposal` votable type with the new
 * KIND_JUDICIARY_OVERRIDE proposal kind (the exec_delegation precedent — one
 * new kind, zero new votable type). The threshold is the PROTECTED
 * supermajority via the `judiciary_override` vote type (category supermajority,
 * denominator serving); it is never re-derived here.
 *
 * On adoption WITHIN the CLK-11 window: the finding is overruled, the law stands
 * UNCHANGED (no law_version appended), both timers cancelled (delegated to
 * ConstitutionalChallengeService::closeOverridden).
 */
class JudiciaryOverrideService
{
    public function __construct(
        private readonly ChamberVoteService $votes,
        private readonly ConstitutionalChallengeService $challenges,
    ) {}

    /**
     * F-LEG-035: open a supermajority override vote against an open challenge.
     * Rejected when the CLK-11 window has closed (an override filed after the
     * veto window is barred — §5.4 binds it "within a set Judicial veto window").
     *
     * @return array{proposal_id:string, vote_id:string, challenge_id:string}
     */
    public function propose(
        Legislature $legislature,
        LegislatureMember $proposer,
        ConstitutionalChallenge $challenge,
        ?string $dissentText = null,
    ): array {
        if ($challenge->status !== ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN) {
            throw new ConstitutionalViolation(
                "An override answers a finding whose window is open (challenge status: {$challenge->status}).",
                'Art. IV §5'
            );
        }

        $recommendation = RemedyRecommendation::query()->find((string) $challenge->remedy_id);

        if ($recommendation === null) {
            throw new ConstitutionalViolation('No remedy window is open on this challenge.', 'Art. IV §5');
        }

        // §5.4 — the CLK-11 veto window must still be open at FILING time.
        if (now()->greaterThan($recommendation->veto_closes_at)) {
            throw new ConstitutionalViolation(
                'The judicial veto window has closed — a supermajority override must be adopted within the '
                .'set window (Art. IV §5.4); the window has expired.',
                'Art. IV §5'
            );
        }

        $proposal = ChamberVoteProposal::create([
            'legislature_id' => (string) $legislature->id,
            'proposal_kind' => ChamberVoteProposal::KIND_JUDICIARY_OVERRIDE,
            'payload' => [
                'challenge_id' => (string) $challenge->id,
                'dissent_text' => $dissentText,
            ],
            'proposed_by_member_id' => (string) $proposer->id,
            'status' => ChamberVoteProposal::STATUS_OPEN,
        ]);

        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: 'judiciary_override', // supermajority of serving — the PROTECTED threshold
            votable: $proposal,
            stage: ChamberVote::STAGE_FLOOR,
            opener: $proposer,
        );

        $proposal->forceFill(['vote_id' => (string) $vote->id])->save();

        return [
            'proposal_id' => (string) $proposal->id,
            'vote_id' => (string) $vote->id,
            'challenge_id' => (string) $challenge->id,
        ];
    }

    /**
     * Resolve the override vote (delegated from ChamberActService::
     * applyProposalAdoption for KIND_JUDICIARY_OVERRIDE on adoption, and from
     * resolveProposalVote for rejection). Adopted within CLK-11 ⇒ the law
     * stands unchanged, the challenge closes `overridden`; rejected ⇒ the
     * challenge stays legislative_window_open (Path 1/3 still available).
     *
     * @return array{result_type:string, result_id:string}
     */
    public function resolveOverrideAdoption(ChamberVote $vote, ChamberVoteProposal $proposal): array
    {
        $payload = (array) $proposal->payload;
        $challenge = ConstitutionalChallenge::query()->find((string) ($payload['challenge_id'] ?? ''));

        if ($challenge === null) {
            return ['result_type' => 'constitutional_challenges', 'result_id' => ''];
        }

        // Defensive: a vote that somehow closed adopted AFTER the window must
        // not override (the propose() gate is the primary guard; this is the
        // belt). A late-closing adopted vote leaves the challenge open.
        $recommendation = RemedyRecommendation::query()->find((string) $challenge->remedy_id);

        if ($recommendation !== null && now()->greaterThan($recommendation->veto_closes_at)) {
            return ['result_type' => 'constitutional_challenges', 'result_id' => (string) $challenge->id];
        }

        $tally = $this->yesAndRequired($vote);

        $this->challenges->closeOverridden($challenge, $vote, $tally['yes'], $tally['required']);

        return ['result_type' => 'constitutional_challenges', 'result_id' => (string) $challenge->id];
    }

    /**
     * A FAILED override leaves the challenge legislative_window_open (Path 1/3
     * remain available; the clocks keep running). Audit only — no transition.
     */
    public function noteOverrideFailed(ChamberVoteProposal $proposal): void
    {
        $payload = (array) $proposal->payload;

        app(\App\Services\AuditService::class)->append(
            module: 'judiciary',
            event: 'challenge.override_failed',
            payload: ['challenge_id' => (string) ($payload['challenge_id'] ?? ''), 'proposal_id' => (string) $proposal->id],
            ref: 'F-LEG-035',
        );
    }

    /** Sum the adopted yes count + the per-lane required threshold (display/audit). */
    private function yesAndRequired(ChamberVote $vote): array
    {
        $tallies = DB::table('chamber_vote_tallies')
            ->where('vote_id', (string) $vote->id)
            ->get(['required_yes', 'yes']);

        $yes = (int) $tallies->sum('yes');
        $required = (int) $tallies->sum('required_yes');

        return ['yes' => $yes, 'required' => $required];
    }
}
