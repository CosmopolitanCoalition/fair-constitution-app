<?php

namespace App\Services\Legislature;

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Domain\Counting\CountResult;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Services\AuditService;
use App\Services\ChamberVoteService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\VoteCountingService;
use Illuminate\Support\Facades\DB;

/**
 * Speaker election + seating (chamber ops §B).
 *
 * F-LEG-008 is a SUPERMAJORITY RCV: the PROTECTED VoteCountingService::
 * countRcv provides the rounds, but the WIN CONDITION is the peg
 * supermajority — the final winner's tally must reach `required_yes`
 * SNAPSHOTTED on the vote at open (resolved through
 * ConstitutionalValidator::supermajority(serving) by the vote engine;
 * never recomputed here). Non-casters and exhausted ballots stay in the
 * denominator by construction: required_yes is fixed over serving, not
 * over ballots. The engine's ChamberVoteService::closeRcv() implements
 * this live; supermajorityRcvOutcome() below is the PURE mirror of the
 * same semantics, pinned DB-free by SupermajorityRcvTest so drift between
 * the two is a test failure.
 *
 * Repeat-balloting posture (WF-LEG-02): the engine never auto-loops — a
 * failed balloting closes `failed` and the chamber opens a new ballot
 * ("Open new ballot" on the console). A failed RE-election ballot leaves
 * the incumbent seated ("serves until next legislature unless replaced").
 *
 * Casting on speaker ballots includes the incumbent Speaker (constitutive
 * election of the body — Art. II §3 · as implemented, chamber ops §B.2;
 * the engine's speaker-neutrality guard is yes/no-scoped).
 */
class SpeakerService
{
    public function __construct(
        private readonly ChamberVoteService $votes,
        private readonly PublicRecordService $records,
        private readonly AuditService $audit,
        private readonly RoleService $roles,
    ) {
    }

    // =========================================================================
    // Pure win condition (pinned DB-free by SupermajorityRcvTest)
    // =========================================================================

    /**
     * IRV rounds over public rankings + the supermajority-of-serving win
     * condition — the pure mirror of ChamberVoteService::closeRcv() for
     * rcv_supermajority ballots.
     *
     * @param  list<string>  $candidateIds  serving member ids (any member is nominable)
     * @param  list<list<string>>  $rankings  one ordered member-id list per cast
     * @param  int  $requiredYes  the vote row's snapshotted supermajority threshold
     * @return array{winner: string|null, reason: string|null, result: CountResult}
     */
    public static function supermajorityRcvOutcome(
        array $candidateIds,
        array $rankings,
        int $requiredYes,
        string $tieSeedBase = '',
    ): array {
        $result = (new VoteCountingService)->countRcv(new CountInput(
            $candidateIds,
            1,
            BallotSet::fromRankings($rankings),
            [],
            $tieSeedBase,
        ));

        if ($result->totalValid === 0 || $result->elected === []) {
            return ['winner' => null, 'reason' => 'no_ballots', 'result' => $result];
        }

        $winnerId   = $result->elected[0]['candidacy_id'];
        $finalTally = $result->finalTallies[$winnerId] ?? 0;

        // Peg win condition: final support must reach the SNAPSHOTTED
        // supermajority of serving — exhausted ballots and non-casters
        // remain in the denominator because requiredYes never shrinks.
        if ($finalTally < $requiredYes * VoteCountingService::SCALE) {
            return ['winner' => null, 'reason' => 'no_supermajority', 'result' => $result];
        }

        return ['winner' => $winnerId, 'reason' => null, 'result' => $result];
    }

    // =========================================================================
    // Balloting (the F-LEG-008 handler delegates here)
    // =========================================================================

    /**
     * Open a speaker balloting: by system (first session — no R-10 exists
     * yet) or via an adopted replace_speaker motion (auto-recognized —
     * the chair cannot block its own replacement). One open speaker
     * ballot per chamber. Free-standing vote (no votable — design §B.1).
     */
    public function openBallot(Legislature $legislature, ?LegislatureMember $opener = null): ChamberVote
    {
        if ($this->openBallotFor($legislature) !== null) {
            throw new ConstitutionalViolation(
                'A speaker balloting is already open for this chamber — close it before opening another.',
                'Art. II §3'
            );
        }

        $voteType = $legislature->speaker_id === null ? 'speaker_elect' : 'speaker_replace';

        return $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: $voteType,
            opener: $opener,
        );
    }

    /** The chamber's open speaker ballot, if any. */
    public function openBallotFor(Legislature $legislature): ?ChamberVote
    {
        return ChamberVote::query()
            ->where('body_type', ChamberVote::BODY_LEGISLATURE)
            ->where('body_id', (string) $legislature->id)
            ->whereIn('vote_type', ['speaker_elect', 'speaker_replace'])
            ->where('status', ChamberVote::STATUS_OPEN)
            ->first();
    }

    /**
     * Record one member's F-LEG-008 cast. The engine auto-closes at full
     * participation (closeRcv: countRcv rounds + the peg supermajority win
     * condition); a closed-adopted ballot seats its winner here, in the
     * same engine transaction as the final cast.
     *
     * @return array{vote_id: string, closed: bool, outcome: string|null,
     *               winner_member_id: string|null}
     */
    public function recordCast(
        ChamberVote $vote,
        Legislature $legislature,
        LegislatureMember $member,
        array $rankings,
        ?string $explanation = null,
    ): array {
        $this->assertRankingsAreServingMembers($legislature, $rankings);

        $this->votes->cast(
            vote: $vote,
            member: $member,
            value: null,
            rankings: $rankings,
            explanation: $explanation,
            viaForm: 'F-LEG-008',
        );

        return $this->resolveIfClosed($vote->refresh(), $legislature);
    }

    /**
     * Presiding/deadline close (WF-LEG-02 — not the all-cast path), then
     * the same seating resolution.
     */
    public function closeBallot(ChamberVote $vote, Legislature $legislature, ?LegislatureMember $closer = null): array
    {
        $this->votes->close($vote, $closer);

        return $this->resolveIfClosed($vote->refresh(), $legislature);
    }

    /**
     * Speaker ballots are free-standing (no votable), so seating cannot
     * ride the engine's votable-effect dispatch — the F-LEG-008/close
     * paths resolve it here instead.
     */
    private function resolveIfClosed(ChamberVote $vote, Legislature $legislature): array
    {
        if ($vote->status !== ChamberVote::STATUS_CLOSED) {
            return [
                'vote_id'          => (string) $vote->id,
                'closed'           => false,
                'outcome'          => null,
                'winner_member_id' => null,
            ];
        }

        $winnerMemberId = $vote->rcv_record['winner_member_id'] ?? null;

        if ($vote->outcome === ChamberVote::OUTCOME_ADOPTED && $winnerMemberId !== null) {
            $winner = LegislatureMember::query()
                ->whereKey($winnerMemberId)
                ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
                ->firstOrFail();

            $this->seat($legislature, $winner, (string) $vote->id);
        }

        return [
            'vote_id'          => (string) $vote->id,
            'closed'           => true,
            'outcome'          => (string) $vote->outcome,
            'winner_member_id' => $vote->outcome === ChamberVote::OUTCOME_ADOPTED ? $winnerMemberId : null,
        ];
    }

    // =========================================================================
    // Seating (Art. II §3) — authoritative fact = legislatures.speaker_id
    // =========================================================================

    public function seat(Legislature $legislature, LegislatureMember $winner, ?string $voteId = null): void
    {
        $run = function () use ($legislature, $winner, $voteId): void {
            $previousSpeakerId = $legislature->speaker_id !== null ? (string) $legislature->speaker_id : null;

            // Clear the prior speaker's denormalized flag.
            LegislatureMember::query()
                ->where('legislature_id', $legislature->id)
                ->where('is_speaker', true)
                ->update(['is_speaker' => false, 'updated_at' => now()]);

            $legislature->forceFill(['speaker_id' => $winner->id])->save();
            $winner->forceFill(['is_speaker' => true])->save();

            $this->records->publish(
                kind: 'certification',
                title: 'Speaker elected by supermajority ranked-choice ballot',
                body: sprintf(
                    'Member %s elected Speaker of legislature %s%s (Art. II §3).',
                    (string) $winner->id,
                    (string) $legislature->id,
                    $previousSpeakerId !== null ? ', replacing the prior Speaker' : ''
                ),
                attrs: [
                    'actor_user_id'   => (string) $winner->user_id,
                    'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                    'legislature_id'  => (string) $legislature->id,
                    'via_form'        => 'F-LEG-008',
                    'subject_type'    => 'legislature_members',
                    'subject_id'      => (string) $winner->id,
                ],
            );

            $this->audit->append(
                module: 'legislature',
                event: 'speaker.seated',
                payload: [
                    'legislature_id'      => (string) $legislature->id,
                    'speaker_member_id'   => (string) $winner->id,
                    'speaker_user_id'     => (string) $winner->user_id,
                    'previous_speaker_id' => $previousSpeakerId,
                    'vote_id'             => $voteId,
                    'citation'            => 'Art. II §3',
                ],
                ref: 'F-LEG-008',
                jurisdictionId: (string) $legislature->jurisdiction_id,
            );

            // R-10 derives from legislatures.speaker_id — flush both hands.
            $this->roles->flushUser((string) $winner->user_id);

            if ($previousSpeakerId !== null) {
                $previousUserId = LegislatureMember::query()->whereKey($previousSpeakerId)->value('user_id');

                if ($previousUserId !== null) {
                    $this->roles->flushUser((string) $previousUserId);
                }
            }
        };

        DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function assertRankingsAreServingMembers(Legislature $legislature, array $rankings): void
    {
        if ($rankings === []) {
            throw new ConstitutionalViolation('A speaker ballot must rank at least one candidate.', 'Art. II §3');
        }

        $serving = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->current()
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $unknown = array_diff(array_map('strval', $rankings), $serving);

        if ($unknown !== []) {
            throw new ConstitutionalViolation(
                'Speaker ballot rankings must name serving members of this chamber (candidates = the chamber itself); '
                . 'unknown: ' . implode(', ', $unknown) . '.',
                'Art. II §3'
            );
        }

        if (count($rankings) !== count(array_unique(array_map('strval', $rankings)))) {
            throw new ConstitutionalViolation('Speaker ballot rankings may not repeat a candidate.', 'Art. II §3');
        }
    }
}
