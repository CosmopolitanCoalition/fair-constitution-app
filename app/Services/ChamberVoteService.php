<?php

namespace App\Services;

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\CommitteeRoster;
use App\Models\ChamberVote;
use App\Models\ChamberVoteTally;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\LegislatureSession;
use App\Models\SessionAttendance;
use App\Models\User;
use App\Models\VoteCast;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * C-V2 (PHASE_C_DESIGN_votes_laws §B) — THE chamber vote engine.
 *
 * ONE code path for unicameral floor, committee, and each bicameral kind:
 * per-kind tallies are LANE rows (chamber_vote_tallies), every lane runs
 * identical quorum + threshold math, and a vote adopts only when EVERY
 * lane independently passes (q-ledger #q7 — bicameral dual agreement, at
 * committee AND floor).
 *
 * Thresholds resolve ONLY through the two PROTECTED functions —
 * ConstitutionalValidator::quorum() / ::supermajority() — SNAPSHOTTED
 * onto the lane rows at open. The denominator is ALL serving members of
 * the lane: a vacant seat is not serving; an absent or abstaining member
 * stays in the denominator and is arithmetically identical to a no
 * (hardened; abstain is recorded distinctly for the public record only).
 * The Speaker IS a serving member and stays in every denominator; they
 * may not cast except via F-SPK-004 (tiebreak() — the seam the
 * chamber-ops F-SPK-004 handler calls).
 *
 * Every method is called from INSIDE engine handlers (never controllers):
 * mutations ride the engine transaction; this service appends its own
 * vote.opened / vote.closed / vote.tiebreak chain entries and publishes
 * each cast to public_records (member votes are PUBLIC, Art. II §2 —
 * the exact opposite of ballots).
 *
 * The pure threshold/outcome math lives in the static helpers at the
 * bottom (laneThresholds / laneResult / voteOutcome / lanePlan /
 * assertMemberMayCast) so tests/Constitutional/{PegQuorumTest,
 * BicameralDualAgreementTest} can pin it DB-free, asserting through the
 * service while it delegates to the PROTECTED functions.
 */
class ChamberVoteService
{
    /**
     * §B.4 — Art. II §2 order of business: while a session has pending
     * locked slot-1 (emergency) agenda items, only these vote types may
     * open. Constitutional-matter types join in Phase E.
     */
    public const FIRST_BUSINESS_VOTE_TYPES = [
        'emergency_invoke', 'emergency_renew', 'procedural_motion',
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly SettingsResolver $settings,
        private readonly PublicRecordService $records,
        private readonly CommitteeRoster $roster,
        private readonly VoteCountingService $counter,
    ) {
    }

    // =========================================================================
    // open
    // =========================================================================

    /**
     * Open a chamber vote: resolve the vote type, build the lanes, and
     * snapshot every threshold through the PROTECTED functions.
     *
     * @param  string|null  $basisOverride  bills with act_type
     *         'supermajority'/'dual_supermajority' fix a supermajority
     *         basis onto an otherwise-majority vote_type at introduction
     *         (ESM-07 — the registry keys are fixed; the act_type is the
     *         constitutional input).
     */
    public function open(
        string $bodyType,
        string $bodyId,
        string $voteType,
        ?Model $votable = null,
        ?string $stage = null,
        ?LegislatureSession $session = null,
        ?LegislatureMember $opener = null,
        ?string $basisOverride = null,
        ?DateTimeInterface $closesAt = null,
    ): ChamberVote {
        $config = self::voteTypeConfig($voteType);

        if ($config['engine'] !== 'chamber') {
            throw new InvalidArgumentException(
                "Vote type [{$voteType}] runs on the [{$config['engine']}] engine — not a chamber vote."
            );
        }

        $basis = $basisOverride ?? $config['basis'];

        if ($basisOverride !== null && ! in_array($basisOverride, ['majority', 'supermajority'], true)) {
            throw new InvalidArgumentException("Illegal basis override [{$basisOverride}].");
        }

        $method         = str_starts_with($basis, 'rcv') ? ChamberVote::METHOD_RCV : ChamberVote::METHOD_YES_NO;
        $thresholdBasis = in_array($basis, ['supermajority', 'rcv_supermajority'], true)
            ? ChamberVote::BASIS_SUPERMAJORITY
            : ChamberVote::BASIS_MAJORITY;

        // ── Resolve the body → legislature/jurisdiction + lane counts ───────
        // Per-kind lanes apply only where the registry says so (q7 keys);
        // constitutive whole-body RCV elections (speaker, chair —
        // bicameral 'n/a') always run one 'all' lane over ALL serving.
        $perKind = ($config['bicameral'] ?? 'n/a') === 'per_kind';

        // Phase D (Art. III §6 enforcement posture): an INVALID board
        // cannot open a board vote except the cure path (chair election /
        // seating side-effects). Already-open votes close normally —
        // snapshot-at-open discipline.
        if ($bodyType === ChamberVote::BODY_BOARD) {
            \App\Services\Organizations\OrgBoardService::assertBoardMayOpenVote(
                \App\Models\Board::query()->findOrFail($bodyId),
                $voteType,
            );
        }

        [$legislature, $jurisdictionId, $laneCounts] = $this->resolveBody($bodyType, $bodyId, $perKind);

        // ── Art. II §2 session order: emergency business first ──────────────
        if ($session !== null
            && $session->pendingFirstBusiness() !== []
            && ! in_array($voteType, self::FIRST_BUSINESS_VOTE_TYPES, true)) {
            throw new ConstitutionalViolation(
                'Active emergency powers are the first order of business — '
                . "general business ({$voteType}) cannot open while slot-1 agenda items are pending.",
                'Art. II §2'
            );
        }

        // ── Per-lane threshold snapshots (PROTECTED functions only) ─────────
        $numerator   = $this->settings->resolveInt($jurisdictionId, 'supermajority_numerator', 2);
        $denominator = $this->settings->resolveInt($jurisdictionId, 'supermajority_denominator', 3);

        $lanes = [];
        foreach ($laneCounts as $lane => $serving) {
            $lanes[$lane] = self::laneThresholds((int) $serving, $thresholdBasis, $numerator, $denominator);
        }

        $vote = ChamberVote::create([
            'body_type'           => $bodyType,
            'body_id'             => $bodyId,
            'legislature_id'      => $legislature?->id,
            'jurisdiction_id'     => $jurisdictionId,
            'votable_type'        => $votable !== null ? self::votableType($votable) : null,
            'votable_id'          => $votable?->getKey(),
            'vote_type'           => $voteType,
            'vote_method'         => $method,
            'threshold_basis'     => $thresholdBasis,
            'stage'               => $stage,
            'bicameral'           => count($lanes) > 1,
            'serving_snapshot'    => array_sum(array_column($lanes, 'serving')),
            'held_in_session_id'  => $session?->id,
            'opened_by_member_id' => $opener?->id,
            'opened_at'           => now(),
            'closes_at'           => $closesAt,
            'status'              => ChamberVote::STATUS_OPEN,
        ]);

        foreach ($lanes as $lane => $thresholds) {
            ChamberVoteTally::create([
                'vote_id'         => $vote->id,
                'lane'            => $lane,
                'serving'         => $thresholds['serving'],
                'quorum_required' => $thresholds['quorum_required'],
                'required_yes'    => $thresholds['required_yes'],
            ]);
        }

        $this->audit->append(
            module: 'legislature',
            event: 'vote.opened',
            payload: [
                'vote_id'         => $vote->id,
                'vote_type'       => $voteType,
                'body_type'       => $bodyType,
                'body_id'         => $bodyId,
                'stage'           => $stage,
                'threshold_basis' => $thresholdBasis,
                'votable_type'    => $vote->votable_type,
                'votable_id'      => $vote->votable_id,
                'lanes'           => array_map(
                    fn (string $lane) => ['lane' => $lane] + $lanes[$lane],
                    array_keys($lanes)
                ),
            ],
            ref: 'WF-LEG-06',
            jurisdictionId: $jurisdictionId,
        );

        return $vote->load('tallies');
    }

    // =========================================================================
    // cast
    // =========================================================================

    /**
     * Record one member's PUBLIC vote. One transaction: cast row + lane
     * counter + public_records 'vote' row. Auto-closes when every member
     * able to cast has cast ("all serving cast" — the deadline close is
     * the explicit close() path).
     */
    public function cast(
        ChamberVote $vote,
        LegislatureMember $member,
        ?string $value,
        ?array $rankings = null,
        ?string $explanation = null,
        string $viaForm = 'F-LEG-004',
    ): VoteCast {
        $run = function () use ($vote, $member, $value, $rankings, $explanation, $viaForm): VoteCast {
            $fresh = ChamberVote::query()->whereKey($vote->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== ChamberVote::STATUS_OPEN) {
                throw new ConstitutionalViolation(
                    "Vote {$fresh->id} is not open (status: {$fresh->status}).",
                    'Art. II §2'
                );
            }

            if (! in_array($member->status, LegislatureMember::CURRENT_STATUSES, true)) {
                throw new ConstitutionalViolation(
                    'Only currently serving members may cast.',
                    'Art. II §2'
                );
            }

            $lane = $this->laneForMember($fresh, $member);

            // Speaker neutrality (Art. II §3): the Speaker stays in every
            // denominator but may not cast on yes/no business except via
            // F-SPK-004. Constitutive RCV elections (speaker/chair) are
            // cast by ALL serving members, Speaker included.
            $speakerId = $fresh->legislature_id !== null
                ? Legislature::query()->whereKey($fresh->legislature_id)->value('speaker_id')
                : null;

            self::assertMemberMayCast(
                isSpeaker: $speakerId !== null && (string) $speakerId === (string) $member->id,
                voteMethod: $fresh->vote_method,
                viaForm: $viaForm,
            );

            // Method/value pairing (the DB CHECK is the backstop).
            if ($fresh->vote_method === ChamberVote::METHOD_YES_NO) {
                if (! in_array($value, [VoteCast::VALUE_YES, VoteCast::VALUE_NO, VoteCast::VALUE_ABSTAIN], true) || $rankings !== null) {
                    throw new ConstitutionalViolation(
                        'A yes/no vote takes exactly one of yes|no|abstain (no rankings).',
                        'Art. II §2'
                    );
                }
            } else {
                if ($value !== null || ! is_array($rankings) || $rankings === []) {
                    throw new ConstitutionalViolation(
                        'A ranked vote takes a non-empty ranking list (no yes/no value).',
                        'Art. II §2'
                    );
                }
                $rankings = array_values(array_map('strval', $rankings));
            }

            if (VoteCast::query()->where('vote_id', $fresh->id)->where('member_id', $member->id)->exists()) {
                throw new ConstitutionalViolation(
                    'Member has already cast on this vote — casts are immutable (the record is the record).',
                    'Art. II §2'
                );
            }

            $cast = VoteCast::create([
                'vote_id'       => $fresh->id,
                'member_id'     => $member->id,
                'lane'          => $lane,
                'value'         => $value,
                'rankings'      => $rankings,
                'explanation'   => $explanation,
                'cast_via_form' => $viaForm,
                'cast_at'       => now(),
            ]);

            if ($fresh->vote_method === ChamberVote::METHOD_YES_NO) {
                ChamberVoteTally::query()
                    ->where('vote_id', $fresh->id)
                    ->where('lane', $lane)
                    ->increment($value); // column name = value (yes|no|abstain)
            }

            // PUBLIC by constitutional mandate (Art. II §2): every cast is
            // a public record — value/rankings + explanation, named member.
            $record = $this->records->publish(
                kind: 'vote',
                title: sprintf(
                    'Vote cast on %s — %s',
                    $fresh->vote_type,
                    $fresh->vote_method === ChamberVote::METHOD_YES_NO ? $value : 'ranked ballot'
                ),
                body: $explanation,
                attrs: [
                    'actor_user_id'  => (string) $member->user_id,
                    'jurisdiction_id'=> (string) $fresh->jurisdiction_id,
                    'legislature_id' => $fresh->legislature_id !== null ? (string) $fresh->legislature_id : null,
                    'via_form'       => $viaForm,
                    'subject_type'   => 'chamber_vote',
                    'subject_id'     => (string) $fresh->id,
                ],
            );

            $cast->forceFill(['public_record_id' => $record->id])->save();

            // Auto-close at full participation ("all serving cast"): on
            // yes/no votes the Speaker structurally cannot cast, so the
            // expected count excludes them; RCV elections include everyone.
            $expected = $fresh->serving_snapshot;
            if ($fresh->vote_method === ChamberVote::METHOD_YES_NO
                && $speakerId !== null
                && $this->memberBelongsToBody($fresh, (string) $speakerId)) {
                $expected -= 1;
            }

            if (VoteCast::query()->where('vote_id', $fresh->id)->where('is_tiebreak', false)->count() >= $expected) {
                $this->close($fresh);
            }

            return $cast;
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    // =========================================================================
    // castBoardSeat — Phase D (D-O8: body_type='board' votes)
    // =========================================================================

    /**
     * Record one BOARD SEAT's public vote on a body_type='board' chamber
     * vote (the joint-chair RCV and board yes/no business — Art. III §6).
     * Identical discipline to cast(): one transaction, immutable cast row
     * (vote_casts.board_seat_id — the D-O8 XOR), lane counter, public
     * record, auto-close at full participation. No Speaker logic — boards
     * have no presiding neutrality rule; every seated seat casts.
     *
     * `cast_via_form` records 'WF-ORG-05' — FLAGGED REGISTRY GAP: the
     * catalog carries no board-member ballot form; the workflow ref keeps
     * the record honest until the registry grows one.
     */
    public function castBoardSeat(
        ChamberVote $vote,
        \App\Models\BoardSeat $seat,
        ?string $value,
        ?array $rankings = null,
        ?string $explanation = null,
        string $viaForm = 'WF-ORG-05',
    ): VoteCast {
        $run = function () use ($vote, $seat, $value, $rankings, $explanation, $viaForm): VoteCast {
            $fresh = ChamberVote::query()->whereKey($vote->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== ChamberVote::STATUS_OPEN) {
                throw new ConstitutionalViolation(
                    "Vote {$fresh->id} is not open (status: {$fresh->status}).",
                    'Art. III §6'
                );
            }

            if ($fresh->body_type !== ChamberVote::BODY_BOARD) {
                throw new ConstitutionalViolation(
                    'Board-seat casts belong to board votes — chamber members cast through their member row.',
                    'Art. III §6 · as implemented'
                );
            }

            if ((string) $seat->board_id !== (string) $fresh->body_id
                || $seat->status !== \App\Models\BoardSeat::STATUS_SEATED) {
                throw new ConstitutionalViolation(
                    'Only currently SEATED members of this board may cast on its votes.',
                    'Art. III §6'
                );
            }

            // Method/value pairing — identical to cast().
            if ($fresh->vote_method === ChamberVote::METHOD_YES_NO) {
                if (! in_array($value, [VoteCast::VALUE_YES, VoteCast::VALUE_NO, VoteCast::VALUE_ABSTAIN], true) || $rankings !== null) {
                    throw new ConstitutionalViolation(
                        'A yes/no vote takes exactly one of yes|no|abstain (no rankings).',
                        'Art. III §6 · as implemented'
                    );
                }
            } else {
                if ($value !== null || ! is_array($rankings) || $rankings === []) {
                    throw new ConstitutionalViolation(
                        'A ranked vote takes a non-empty ranking list (no yes/no value).',
                        'Art. III §6 · as implemented'
                    );
                }
                $rankings = array_values(array_map('strval', $rankings));
            }

            if (VoteCast::query()->where('vote_id', $fresh->id)->where('board_seat_id', $seat->id)->exists()) {
                throw new ConstitutionalViolation(
                    'This seat has already cast on this vote — casts are immutable (the record is the record).',
                    'Art. III §6 · as implemented'
                );
            }

            $cast = VoteCast::create([
                'vote_id'       => $fresh->id,
                'member_id'     => null,
                'board_seat_id' => $seat->id,
                'lane'          => ChamberVoteTally::LANE_ALL,
                'value'         => $value,
                'rankings'      => $rankings,
                'explanation'   => $explanation,
                'cast_via_form' => $viaForm,
                'cast_at'       => now(),
            ]);

            if ($fresh->vote_method === ChamberVote::METHOD_YES_NO) {
                ChamberVoteTally::query()
                    ->where('vote_id', $fresh->id)
                    ->where('lane', ChamberVoteTally::LANE_ALL)
                    ->increment($value);
            }

            // Board votes are governance acts — PUBLIC, like every
            // chamber cast (the exact opposite of ballots).
            $record = $this->records->publish(
                kind: 'vote',
                title: sprintf(
                    'Board vote cast on %s — %s',
                    $fresh->vote_type,
                    $fresh->vote_method === ChamberVote::METHOD_YES_NO ? $value : 'ranked ballot'
                ),
                body: $explanation,
                attrs: [
                    'actor_user_id'   => $seat->holder_user_id !== null ? (string) $seat->holder_user_id : null,
                    'jurisdiction_id' => (string) $fresh->jurisdiction_id,
                    'via_workflow'    => $viaForm,
                    'subject_type'    => 'chamber_vote',
                    'subject_id'      => (string) $fresh->id,
                ],
            );

            $cast->forceFill(['public_record_id' => $record->id])->save();

            // Auto-close at full participation (every seated seat cast).
            if (VoteCast::query()->where('vote_id', $fresh->id)->where('is_tiebreak', false)->count() >= $fresh->serving_snapshot) {
                $this->close($fresh);
            }

            return $cast;
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    // =========================================================================
    // close
    // =========================================================================

    /**
     * Close the vote: per-lane presence + quorum + threshold, outcome =
     * dual agreement across ALL lanes, votable side-effects in the same
     * transaction. Absence/abstention semantics are hardened in
     * laneResult() — the denominator is `serving`, full stop.
     */
    public function close(ChamberVote $vote, ?LegislatureMember $closer = null): ChamberVote
    {
        $run = function () use ($vote): ChamberVote {
            $fresh = ChamberVote::query()->whereKey($vote->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== ChamberVote::STATUS_OPEN) {
                return $fresh->load('tallies'); // idempotent
            }

            if ($fresh->vote_method === ChamberVote::METHOD_RCV) {
                return $this->closeRcv($fresh);
            }

            $laneResults = [];

            foreach ($fresh->tallies()->lockForUpdate()->get() as $tally) {
                $present = $this->presenceFor($fresh, $tally->lane);

                $result = self::laneResult(
                    serving: $tally->serving,
                    quorumRequired: $tally->quorum_required,
                    requiredYes: $tally->required_yes,
                    present: $present,
                    yes: $tally->yes,
                    no: $tally->no,
                );

                $tally->forceFill([
                    'present' => $present,
                    'quorate' => $result['quorate'],
                    'passed'  => $result['passed'],
                ])->save();

                $laneResults[$tally->lane] = $result + [
                    'yes' => $tally->yes, 'no' => $tally->no, 'abstain' => $tally->abstain,
                    'serving' => $tally->serving, 'quorum_required' => $tally->quorum_required,
                    'required_yes' => $tally->required_yes, 'present' => $present,
                ];
            }

            $outcome = self::voteOutcome($laneResults);

            $fresh->forceFill([
                'status'     => ChamberVote::STATUS_CLOSED,
                'outcome'    => $outcome,
                'decided_at' => now(),
            ])->save();

            $failingLanes = array_keys(array_filter($laneResults, fn (array $r) => ! $r['passed']));

            $this->audit->append(
                module: 'legislature',
                event: 'vote.closed',
                payload: [
                    'vote_id'       => $fresh->id,
                    'vote_type'     => $fresh->vote_type,
                    'stage'         => $fresh->stage,
                    'outcome'       => $outcome,
                    'lanes'         => array_map(
                        fn (string $lane) => ['lane' => $lane] + $laneResults[$lane],
                        array_keys($laneResults)
                    ),
                    'failing_lanes' => $outcome === ChamberVote::OUTCOME_ADOPTED ? [] : $failingLanes,
                ],
                ref: 'WF-LEG-06',
                jurisdictionId: (string) $fresh->jurisdiction_id,
            );

            if ($outcome !== ChamberVote::OUTCOME_TIED) {
                $this->dispatchVotableEffects($fresh, $outcome);
            }

            return $fresh->load('tallies');
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    // =========================================================================
    // tiebreak — F-SPK-004 seam (the chamber-ops handler calls this)
    // =========================================================================

    /**
     * The ONLY speaker vote (Art. II §3). Guards: the vote closed `tied`;
     * a single vote can actually resolve it (structurally majority-basis
     * only — a supermajority tie is unbreakable by one vote and the form
     * is rejected with citation); the actor is the chamber's Speaker.
     * Bicameral: applies only to the lane matching the Speaker's own seat
     * kind (one person, one vote — flagged interpretation, q-ledger
     * candidate). Records the is_tiebreak cast and re-closes.
     */
    public function tiebreak(ChamberVote $vote, User $speaker, string $value, ?string $explanation = null): ChamberVote
    {
        $run = function () use ($vote, $speaker, $value, $explanation): ChamberVote {
            $fresh = ChamberVote::query()->whereKey($vote->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== ChamberVote::STATUS_CLOSED || $fresh->outcome !== ChamberVote::OUTCOME_TIED) {
                throw new ConstitutionalViolation(
                    'A tie-breaking vote may only be cast on a vote that closed tied.',
                    'Art. II §3'
                );
            }

            if (! in_array($value, [VoteCast::VALUE_YES, VoteCast::VALUE_NO], true)) {
                throw new ConstitutionalViolation(
                    'A tie-breaking vote is yes or no.',
                    'Art. II §3'
                );
            }

            if ($fresh->threshold_basis === ChamberVote::BASIS_SUPERMAJORITY) {
                throw new ConstitutionalViolation(
                    'A supermajority tie is unbreakable by a single vote — the tie-break never manufactures a supermajority.',
                    'Art. II §3 · Art. VII'
                );
            }

            $legislature = Legislature::query()->findOrFail($fresh->legislature_id);

            $speakerMember = LegislatureMember::query()
                ->whereKey($legislature->speaker_id)
                ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
                ->first();

            if ($speakerMember === null || (string) $speakerMember->user_id !== (string) $speaker->getKey()) {
                throw new ConstitutionalViolation(
                    'Only the chamber\'s Speaker may cast the tie-breaking vote.',
                    'Art. II §3'
                );
            }

            // Lane scope: the Speaker's own seat kind (bicameral) or 'all'.
            $lane = $fresh->bicameral ? $speakerMember->seatKind() : ChamberVoteTally::LANE_ALL;

            $tally = $fresh->tallies()->where('lane', $lane)->lockForUpdate()->first();

            if ($tally === null || ! ($tally->yes === $tally->no && $tally->yes === $tally->required_yes - 1)) {
                throw new ConstitutionalViolation(
                    "The {$lane} lane is not in a resolvable tie state for the Speaker's vote.",
                    'Art. II §3'
                );
            }

            $cast = VoteCast::create([
                'vote_id'       => $fresh->id,
                'member_id'     => $speakerMember->id,
                'lane'          => $lane,
                'value'         => $value,
                'explanation'   => $explanation,
                'is_tiebreak'   => true,
                'cast_via_form' => 'F-SPK-004',
                'cast_at'       => now(),
            ]);

            $tally->increment($value);

            $record = $this->records->publish(
                kind: 'vote',
                title: "Speaker tie-breaking vote — {$value}",
                body: $explanation,
                attrs: [
                    'actor_user_id'   => (string) $speakerMember->user_id,
                    'jurisdiction_id' => (string) $fresh->jurisdiction_id,
                    'legislature_id'  => (string) $fresh->legislature_id,
                    'via_form'        => 'F-SPK-004',
                    'subject_type'    => 'chamber_vote',
                    'subject_id'      => (string) $fresh->id,
                ],
            );
            $cast->forceFill(['public_record_id' => $record->id])->save();

            // Re-close: recompute every lane against the UNCHANGED peg
            // thresholds (no special outcome math).
            $laneResults = [];
            foreach ($fresh->tallies()->get() as $t) {
                $result = self::laneResult(
                    serving: $t->serving,
                    quorumRequired: $t->quorum_required,
                    requiredYes: $t->required_yes,
                    present: $t->present ?? ($t->yes + $t->no + $t->abstain),
                    yes: $t->yes,
                    no: $t->no,
                );
                $t->forceFill(['quorate' => $result['quorate'], 'passed' => $result['passed']])->save();
                $laneResults[$t->lane] = $result;
            }

            $outcome = self::voteOutcome($laneResults);

            // A tie-break is terminal: a still-tied lane after the Speaker's
            // one vote (impossible arithmetically) or any failing lane fails.
            if ($outcome === ChamberVote::OUTCOME_TIED) {
                $outcome = ChamberVote::OUTCOME_FAILED;
            }

            $fresh->forceFill([
                'outcome'          => $outcome,
                'speaker_tiebreak' => true,
                'decided_at'       => now(),
            ])->save();

            $this->audit->append(
                module: 'legislature',
                event: 'vote.tiebreak',
                payload: [
                    'vote_id' => $fresh->id,
                    'lane'    => $lane,
                    'value'   => $value,
                    'outcome' => $outcome,
                ],
                ref: 'F-SPK-004',
                actorId: (string) $speakerMember->user_id,
                jurisdictionId: (string) $fresh->jurisdiction_id,
            );

            $this->dispatchVotableEffects($fresh, $outcome);

            return $fresh->load('tallies');
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    // =========================================================================
    // PURE constitutional math — pinned DB-free by the constitutional suite
    // =========================================================================

    /**
     * Per-lane threshold snapshot. DELEGATES to the PROTECTED functions —
     * the only place the vote engine touches threshold arithmetic.
     *
     * @return array{serving: int, quorum_required: int, required_yes: int}
     */
    public static function laneThresholds(int $serving, string $basis, int $numerator = 2, int $denominator = 3): array
    {
        $quorum = ConstitutionalValidator::quorum($serving);

        $requiredYes = $basis === ChamberVote::BASIS_SUPERMAJORITY
            ? ConstitutionalValidator::supermajority($serving, $numerator, $denominator)
            : $quorum;

        return [
            'serving'         => $serving,
            'quorum_required' => $quorum,
            'required_yes'    => $requiredYes,
        ];
    }

    /**
     * Lane plan for a body (pure given the counts): bicameral chambers
     * vote in exactly two kind lanes; everything else in one 'all' lane.
     *
     * @param  array{type_a?: int, type_b?: int, all?: int}  $servingByKind
     * @return array<string, int> lane => serving
     */
    public static function lanePlan(bool $bicameral, array $servingByKind): array
    {
        if (! $bicameral) {
            return [ChamberVoteTally::LANE_ALL => (int) ($servingByKind['all']
                ?? array_sum($servingByKind))];
        }

        return [
            ChamberVoteTally::LANE_TYPE_A => (int) ($servingByKind['type_a'] ?? 0),
            ChamberVoteTally::LANE_TYPE_B => (int) ($servingByKind['type_b'] ?? 0),
        ];
    }

    /**
     * One lane's result. HARDENED: `passed` compares yes against the
     * serving-denominated required_yes — `present` feeds ONLY the quorum
     * gate; an absent or abstaining member is arithmetically identical to
     * a no. The outcome can never be computed from present.
     *
     * @return array{quorate: bool, passed: bool, tie_state: bool}
     */
    public static function laneResult(
        int $serving,
        int $quorumRequired,
        int $requiredYes,
        int $present,
        int $yes,
        int $no,
    ): array {
        $quorate = $present >= $quorumRequired;
        $passed  = $quorate && $yes >= $requiredYes;

        // Resolvable tie: one more yes would pass (and quorum holds) —
        // the F-SPK-004 window. Anything else that misses the threshold
        // is a plain failure.
        $tieState = $quorate && ! $passed && $yes === $no && $yes === $requiredYes - 1;

        return ['quorate' => $quorate, 'passed' => $passed, 'tie_state' => $tieState];
    }

    /**
     * Dual agreement across lanes (q-ledger #q7): adopted iff EVERY lane
     * passed; tied iff nothing failed irrecoverably and at least one lane
     * sits in a resolvable tie; else failed.
     *
     * @param  array<string, array{quorate: bool, passed: bool, tie_state: bool}>  $laneResults
     */
    public static function voteOutcome(array $laneResults): string
    {
        if ($laneResults === []) {
            throw new InvalidArgumentException('A vote has at least one lane.');
        }

        $allPassed = true;
        $anyTie    = false;

        foreach ($laneResults as $result) {
            if ($result['passed']) {
                continue;
            }

            $allPassed = false;

            if ($result['tie_state']) {
                $anyTie = true;
            } else {
                return ChamberVote::OUTCOME_FAILED; // irrecoverable lane
            }
        }

        if ($allPassed) {
            return ChamberVote::OUTCOME_ADOPTED;
        }

        return $anyTie ? ChamberVote::OUTCOME_TIED : ChamberVote::OUTCOME_FAILED;
    }

    /**
     * Speaker neutrality guard (Art. II §3), pure: the Speaker may not
     * cast on yes/no business except via F-SPK-004; constitutive RCV
     * elections are cast by all serving members, Speaker included.
     */
    public static function assertMemberMayCast(bool $isSpeaker, string $voteMethod, string $viaForm): void
    {
        if ($isSpeaker && $voteMethod === ChamberVote::METHOD_YES_NO && $viaForm !== 'F-SPK-004') {
            throw new ConstitutionalViolation(
                'The Speaker votes only to break ties (F-SPK-004) — they remain a serving member '
                . 'and stay in every denominator.',
                'Art. II §3'
            );
        }
    }

    /** Registry lookup with existence check (boot-pinned by VoteTypeRegistryTest). */
    public static function voteTypeConfig(string $voteType): array
    {
        $config = config("constitution.vote_types.{$voteType}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown vote type [{$voteType}] — not in the 33-row registry.");
        }

        return $config;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * @return array{0: ?Legislature, 1: string, 2: array<string, int>}
     */
    private function resolveBody(string $bodyType, string $bodyId, bool $perKind = true): array
    {
        if ($bodyType === ChamberVote::BODY_LEGISLATURE) {
            $legislature = Legislature::query()->findOrFail($bodyId);

            $byKind = LegislatureMember::query()
                ->where('legislature_id', $legislature->id)
                ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
                ->selectRaw('seat_type, count(*) as n')
                ->groupBy('seat_type')
                ->pluck('n', 'seat_type');

            $bicameral = $perKind && (int) $legislature->type_b_seats > 0;

            $lanes = self::lanePlan($bicameral, [
                'type_a' => (int) ($byKind['a'] ?? 0),
                'type_b' => (int) ($byKind['b'] ?? 0),
                'all'    => (int) $byKind->sum(),
            ]);

            return [$legislature, (string) $legislature->jurisdiction_id, $lanes];
        }

        if ($bodyType === ChamberVote::BODY_COMMITTEE) {
            $roster = $this->roster->laneCounts($bodyId);

            if (($roster['lanes'] ?? []) === [] || ($roster['legislature_id'] ?? null) === null) {
                throw new RuntimeException(
                    "Committee [{$bodyId}] is unknown to the bound CommitteeRoster — "
                    . 'the chamber-ops committees substrate is not wired yet (NoopCommitteeRoster).'
                );
            }

            $legislature = Legislature::query()->findOrFail($roster['legislature_id']);

            return [$legislature, (string) ($roster['jurisdiction_id'] ?? $legislature->jurisdiction_id), $roster['lanes']];
        }

        // Phase D (D-O8 — PHASE_D_DESIGN_organizations §C.3): board votes
        // run ONE 'all' lane over the SEATED board seats — owner-elected,
        // worker-elected, and governor seats all cast with equal votes
        // (Art. III §6 "chair elected jointly by entire board").
        // `legislature_id` stays NULL, as the chamber_votes migration
        // anticipated; jurisdiction resolves through the boardable.
        if ($bodyType === ChamberVote::BODY_BOARD) {
            $board = \App\Models\Board::query()->findOrFail($bodyId);

            $seated = \App\Models\BoardSeat::query()
                ->where('board_id', $board->id)
                ->where('status', \App\Models\BoardSeat::STATUS_SEATED)
                ->count();

            if ($seated < 1) {
                throw new ConstitutionalViolation(
                    'A board vote needs at least one seated board member.',
                    'Art. III §6'
                );
            }

            $jurisdictionId = $board->jurisdictionId();

            if ($jurisdictionId === null) {
                throw new RuntimeException("Board [{$bodyId}] resolves to no jurisdiction.");
            }

            return [null, $jurisdictionId, [ChamberVoteTally::LANE_ALL => $seated]];
        }

        throw new RuntimeException("Unknown chamber-vote body type [{$bodyType}].");
    }

    private function laneForMember(ChamberVote $vote, LegislatureMember $member): string
    {
        if ($vote->body_type === ChamberVote::BODY_COMMITTEE) {
            if (! $this->roster->isMember($vote->body_id, (string) $member->id)) {
                throw new ConstitutionalViolation(
                    'Only seated members of the committee may cast on its votes.',
                    'Art. II §2'
                );
            }

            $lane = $this->roster->laneOf($vote->body_id, (string) $member->id);

            if ($lane === null || ! $vote->tallies()->where('lane', $lane)->exists()) {
                throw new ConstitutionalViolation('Member has no lane on this committee vote.', 'Art. V §3');
            }

            return $lane;
        }

        if ((string) $member->legislature_id !== (string) $vote->legislature_id) {
            throw new ConstitutionalViolation(
                'Only members of the voting body may cast.',
                'Art. II §2'
            );
        }

        $lane = $vote->bicameral ? $member->seatKind() : ChamberVoteTally::LANE_ALL;

        if (! $vote->tallies()->where('lane', $lane)->exists()) {
            throw new ConstitutionalViolation("Member's seat kind has no lane on this vote.", 'Art. V §3');
        }

        return $lane;
    }

    private function memberBelongsToBody(ChamberVote $vote, string $memberId): bool
    {
        if ($vote->body_type === ChamberVote::BODY_COMMITTEE) {
            return $this->roster->isMember($vote->body_id, $memberId);
        }

        return LegislatureMember::query()
            ->whereKey($memberId)
            ->where('legislature_id', $vote->legislature_id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->exists();
    }

    /**
     * §B presence rule: floor votes held in a session count lane members
     * with attendance present|compelled; otherwise presence = casts in
     * the lane. Presence feeds ONLY the quorum gate.
     */
    private function presenceFor(ChamberVote $vote, string $lane): int
    {
        if ($vote->held_in_session_id === null || $vote->body_type !== ChamberVote::BODY_LEGISLATURE) {
            return VoteCast::query()->where('vote_id', $vote->id)->where('lane', $lane)->count();
        }

        $query = SessionAttendance::query()
            ->where('session_id', $vote->held_in_session_id)
            ->whereIn('status', SessionAttendance::COUNTED_PRESENT)
            ->whereIn('member_id', function ($sub) use ($vote, $lane) {
                $sub->select('id')
                    ->from('legislature_members')
                    ->where('legislature_id', $vote->legislature_id)
                    ->whereIn('status', LegislatureMember::CURRENT_STATUSES);

                if ($lane !== ChamberVoteTally::LANE_ALL) {
                    $sub->where('seat_type', $lane === ChamberVoteTally::LANE_TYPE_A ? 'a' : 'b');
                }
            });

        return $query->count();
    }

    /**
     * RCV close: VoteCountingService::countRcv over the PUBLIC rankings.
     * For rcv_supermajority (speaker) the final-round winner must reach
     * required_yes of serving, else the balloting fails (re-ballot = a
     * new vote, WF-LEG-02 — the engine never auto-loops).
     */
    private function closeRcv(ChamberVote $vote): ChamberVote
    {
        $casts = VoteCast::query()->where('vote_id', $vote->id)->get();

        // Candidates: every serving member of the body (any member is
        // nominable — neutrality is a duty of the office, not an
        // eligibility test). Board votes (Phase D): every SEATED board
        // seat — owner-elected, worker-elected, governor alike
        // (Art. III §6 joint chair).
        $candidateIds = $vote->body_type === ChamberVote::BODY_BOARD
            ? \App\Models\BoardSeat::query()
                ->where('board_id', $vote->body_id)
                ->where('status', \App\Models\BoardSeat::STATUS_SEATED)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all()
            : LegislatureMember::query()
                ->where('legislature_id', $vote->legislature_id)
                ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

        $ballots = BallotSet::fromRankings(
            $casts->map(fn (VoteCast $c) => array_values($c->rankings ?? []))->filter(fn ($r) => $r !== [])
        );

        // Deterministic public seed: the vote id is published at open.
        $result = $this->counter->countRcv(new CountInput(
            $candidateIds,
            1,
            $ballots,
            [],
            hash('sha256', (string) $vote->id),
        ));

        $tally = $vote->tallies()->lockForUpdate()->first();

        $winnerId = $result->elected[0]['candidacy_id'] ?? null;
        $passed   = $winnerId !== null;

        if ($passed && ($vote->threshold_basis === ChamberVote::BASIS_SUPERMAJORITY
            || $vote->body_type === ChamberVote::BODY_BOARD)) {
            // Peg: final-round tally must reach required_yes OF SERVING —
            // non-casters and exhausted ballots stay in the denominator.
            // Board chair votes (rcv_majority, Phase D): the winner must
            // reach a MAJORITY of ALL SEATED board seats — required_yes
            // snapshots quorum(seated) at open (Art. III §6; the mockup's
            // "12 ballots, majority is 7").
            $finalMicro = $result->finalTallies[$winnerId] ?? 0;
            $passed     = $finalMicro >= $tally->required_yes * VoteCountingService::SCALE;
        }

        $present = $casts->count();

        $tally->forceFill([
            'present' => $present,
            'quorate' => $present >= $tally->quorum_required,
            'passed'  => $passed && $present >= $tally->quorum_required,
        ])->save();

        $outcome = $tally->passed ? ChamberVote::OUTCOME_ADOPTED : ChamberVote::OUTCOME_FAILED;

        $vote->forceFill([
            'status'     => ChamberVote::STATUS_CLOSED,
            'outcome'    => $outcome,
            'decided_at' => now(),
            'rcv_record' => $result->toArray() + ['winner_member_id' => $passed ? $winnerId : null],
        ])->save();

        $this->audit->append(
            module: 'legislature',
            event: 'vote.closed',
            payload: [
                'vote_id'     => $vote->id,
                'vote_type'   => $vote->vote_type,
                'outcome'     => $outcome,
                'winner'      => $passed ? $winnerId : null,
                'record_hash' => $result->recordHash(),
            ],
            ref: 'WF-LEG-02',
            jurisdictionId: (string) $vote->jurisdiction_id,
        );

        $this->dispatchVotableEffects($vote, $outcome);

        return $vote->load('tallies');
    }

    /**
     * Votable side-effects, each in the SAME transaction as the closing
     * filing. Cases this batch knows: motions (ESM-08 consequences) and
     * bills (stage transitions + enactment). Emergency/referendum
     * votables land with their batch; unknown votables are a no-op (the
     * vote record itself is complete).
     */
    private function dispatchVotableEffects(ChamberVote $vote, string $outcome): void
    {
        if ($vote->votable_type === null || $outcome === ChamberVote::OUTCOME_TIED) {
            return;
        }

        match ($vote->votable_type) {
            'motion' => app(SessionService::class)->resolveMotionVote($vote, $outcome),
            'bill'   => app(BillService::class)->resolveBillVote($vote, $outcome),
            // Chamber-ops batch (PHASE_C_DESIGN_chamber_ops §C/§D/§E):
            // creation-act proposals, appointment consents, removal
            // proceedings, committee chair elections.
            'chamber_vote_proposal' => app(\App\Services\Legislature\ChamberActService::class)->resolveProposalVote($vote, $outcome),
            'appointment_consent'   => app(\App\Services\Legislature\ChamberActService::class)->resolveConsentVote($vote, $outcome),
            'removal_proceeding'    => app(\App\Services\Legislature\OversightService::class)->resolveRemovalVote($vote, $outcome),
            'committee'             => app(\App\Services\Legislature\CommitteeService::class)->resolveChairVote($vote, $outcome),
            // Phase D (D-O8): board joint-chair election (Art. III §6).
            'board'                 => app(\App\Services\Organizations\OrgBoardService::class)->resolveChairVote($vote, $outcome),
            // Phase D executive scope (PHASE_D_DESIGN_executive §B/§C/§D):
            // constituent consent (F-LEG-015 dual leg — generic, Phase E
            // judiciary conversion reuses it), governor removal (ordinary
            // majority, owner ruling #14), department policy proposals
            // (the board decides).
            'constituent_consent' => app(\App\Services\Executive\ExecutiveFormationService::class)->resolveConstituentConsentVote($vote, $outcome),
            'governor_removal'    => app(\App\Services\Executive\BoardGovernorService::class)->resolveRemovalVote($vote, $outcome),
            'policy_proposal'     => app(\App\Services\Executive\DepartmentService::class)->resolvePolicyVote($vote, $outcome),
            default  => null,
        };
    }

    private static function votableType(Model $votable): string
    {
        return match ($votable::class) {
            \App\Models\Motion::class              => 'motion',
            \App\Models\Bill::class                => 'bill',
            // Chamber-ops votables (sibling batch):
            \App\Models\ChamberVoteProposal::class => 'chamber_vote_proposal',
            \App\Models\Appointment::class         => 'appointment_consent',
            \App\Models\RemovalProceeding::class   => 'removal_proceeding',
            \App\Models\Committee::class           => 'committee',
            // Phase D (D-O8): board chair elections carry the board itself.
            \App\Models\Board::class               => 'board',
            // Phase D executive votables:
            \App\Models\ConstituentConsent::class      => 'constituent_consent',
            \App\Models\GovernorRemovalRequest::class  => 'governor_removal',
            \App\Models\PolicyProposal::class          => 'policy_proposal',
            default                                => str_replace('\\', '.', strtolower(class_basename($votable))),
        };
    }
}
