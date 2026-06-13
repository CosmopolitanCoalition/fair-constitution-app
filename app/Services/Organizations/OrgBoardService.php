<?php

namespace App\Services\Organizations;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\Election;
use App\Models\Organization;
use App\Services\AuditService;
use App\Services\ChamberVoteService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;

/**
 * D-O4/D-O2 (PHASE_D_DESIGN_organizations §B.3/§C.3) — board provisioning,
 * seat reconciliation, the composition-validity rule, and the joint chair
 * election.
 *
 * Validity (hardened, Art. III §6): composition_valid =
 *   (SEATED worker seats >= required) AND (SEATED worker seats <= owner-
 *   side seats). A board with vacant constitutionally-required worker
 *   seats is INVALID — it cannot ACT (ChamberVoteService rejects board
 *   votes except the cure path) but can always CURE (the worker-track
 *   election + chair election stay open). [The design's §B.3.3
 *   "in-pipeline counts toward validity" reading was overridden by the
 *   Phase D exit criterion: the 100th worker flips composition_valid
 *   FALSE while the curing election runs.]
 *
 * Joint chair (Art. III §6 — "chair elected jointly by entire board"):
 * any seat-count change OR seat-holder change in either class clears
 * chair_seat_id and re-triggers the chair election — RCV by the FULL
 * board via the chamber-vote engine, body_type='board', vote type
 * board_chair_elect (rcv_majority: final-round winner must reach a
 * majority of ALL seated board seats).
 */
class OrgBoardService
{
    /** Vote types an INVALID board may still open (the cure path). */
    public const CURE_VOTE_TYPES = ['board_chair_elect'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly PublicRecordService $records,
        private readonly RoleService $roles,
    ) {
    }

    // =========================================================================
    // Provisioning (F-ORG-003 'provision_board')
    // =========================================================================

    /**
     * Stand up an organization's first board: owner_seats vacant
     * owner-side seat rows ('owner_elected' for private orgs,
     * 'governor' for CGCs — owner ruling #12), worker side empty until
     * co-determination provisions it.
     */
    public function provision(Organization $org, int $ownerSeats, ?int $cycleMonths = null): Board
    {
        if ($org->board_id !== null) {
            throw new ConstitutionalViolation(
                'This organization already has a board.',
                'CGA Forms Catalog (F-ORG-003)'
            );
        }

        if ($ownerSeats < 1 || $ownerSeats > 99) {
            throw new ConstitutionalViolation(
                "Owner-side seats must lie in [1, 99] (got {$ownerSeats}).",
                'Art. III §6 · as implemented'
            );
        }

        $board = Board::create([
            'boardable_type'    => Board::BOARDABLE_ORGANIZATIONS,
            'boardable_id'      => (string) $org->id,
            'owner_seats'       => $ownerSeats,
            'worker_seats'      => 0,
            'worker_headcount'  => (int) $org->worker_count,
            'composition_valid' => true,
            'status'            => Board::STATUS_FORMING,
            'cycle_months'      => $cycleMonths ?? 60,
        ]);

        $ownerClass = $org->is_cgc ? BoardSeat::CLASS_GOVERNOR : BoardSeat::CLASS_OWNER_ELECTED;

        for ($no = 1; $no <= $ownerSeats; $no++) {
            BoardSeat::create([
                'board_id'   => (string) $board->id,
                'seat_class' => $ownerClass,
                'seat_no'    => $no,
                'status'     => BoardSeat::STATUS_VACANT,
            ]);
        }

        $org->forceFill(['board_id' => (string) $board->id])->save();

        return $board;
    }

    // =========================================================================
    // Reconciliation (called ONLY by CoDeterminationService::recompute)
    // =========================================================================

    /**
     * Bring the worker-elected seat rows in line with the required
     * entitlement; recompute composition_valid; auto-trigger the
     * worker-track election for newly vacant required seats (WF-ORG-04 —
     * the system path of F-ORG-004: R-23 absence can never stall a
     * constitutionally-required seat).
     *
     * @return array<string, mixed>
     */
    public function reconcile(Board $board, int $required): array
    {
        $created = 0;
        $retired = 0;

        $workerSeats = $board->seats()
            ->where('seat_class', BoardSeat::CLASS_WORKER_ELECTED)
            ->get();

        $live = $workerSeats->whereIn('status', [
            BoardSeat::STATUS_VACANT, BoardSeat::STATUS_NOMINATED, BoardSeat::STATUS_SEATED,
        ]);

        // Provision UP: vacant rows for the shortfall.
        if ($live->count() < $required) {
            $nextNo = (int) $board->seats()->max('seat_no');

            for ($i = $live->count(); $i < $required; $i++) {
                BoardSeat::create([
                    'board_id'   => (string) $board->id,
                    'seat_class' => BoardSeat::CLASS_WORKER_ELECTED,
                    'seat_no'    => ++$nextNo,
                    'status'     => BoardSeat::STATUS_VACANT,
                ]);
                $created++;
            }
        }

        // Provision DOWN: never mid-term — surplus SEATED seats serve out
        // (not refilled at term end); surplus VACANT seats withdraw.
        if ($live->count() > $required) {
            $surplus = $live->count() - $required;

            foreach ($live->where('status', BoardSeat::STATUS_VACANT)->sortByDesc('seat_no')->take($surplus) as $seat) {
                $hasOpenElection = Election::query()
                    ->where('board_id', $board->id)
                    ->where('kind', Election::KIND_ORG_BOARD_WORKER)
                    ->whereNotIn('status', [Election::STATUS_FINAL, Election::STATUS_CANCELLED, Election::STATUS_CERTIFIED])
                    ->exists();

                if (! $hasOpenElection) {
                    $seat->delete(); // soft delete — never seated, no history lost
                    $retired++;
                }
            }
        }

        // Hardened ceiling: seated workers may never exceed the owner side.
        $seatedWorkers   = $board->seats()->where('seat_class', BoardSeat::CLASS_WORKER_ELECTED)->seated()->count();
        $seatedOwnerSide = $board->seatedOwnerSideSeats();
        $vacantWorkers   = $board->seats()->where('seat_class', BoardSeat::CLASS_WORKER_ELECTED)
            ->where('status', BoardSeat::STATUS_VACANT)->count();

        $valid = $seatedWorkers >= $required
            && $seatedWorkers <= max($seatedOwnerSide, (int) $board->owner_seats);

        $wasValid = (bool) $board->composition_valid;
        $board->forceFill(['composition_valid' => $valid])->save();

        $electionOpened = null;

        // WF-ORG-04 system auto-trigger: open the worker-track election
        // for the vacant required seats (the Phase D exit criterion).
        if ($vacantWorkers > 0 && $required > $seatedWorkers) {
            $electionOpened = $this->autoOpenWorkerElection($board, min($vacantWorkers, $required - $seatedWorkers));
        }

        if ($wasValid && ! $valid) {
            // The block + the open WF-ORG-04 event are both on the record.
            $this->records->publish(
                kind: 'act',
                title: 'Board composition invalid — worker representation required (Art. III §6)',
                body: sprintf(
                    'Board %s requires %d worker-elected seat(s) (headcount %d); seated: %d. '
                    . 'The board cannot act until the worker seats are filled; the curing election is open.',
                    (string) $board->id,
                    $required,
                    (int) $board->worker_headcount,
                    $seatedWorkers
                ),
                attrs: [
                    'jurisdiction_id' => $board->jurisdictionId(),
                    'via_workflow'    => 'WF-ORG-04',
                    'subject_type'    => 'boards',
                    'subject_id'      => (string) $board->id,
                ],
            );
        }

        return [
            'required'          => $required,
            'seats_created'     => $created,
            'seats_withdrawn'   => $retired,
            'seated_workers'    => $seatedWorkers,
            'composition_valid' => $valid,
            'election_id'       => $electionOpened,
        ];
    }

    /**
     * The system path of F-ORG-004 (WF-ORG-04): files through the engine
     * so the auto-trigger is a first-class chain entry with the form ref.
     */
    private function autoOpenWorkerElection(Board $board, int $seats): ?string
    {
        $open = Election::query()
            ->where('board_id', $board->id)
            ->where('kind', Election::KIND_ORG_BOARD_WORKER)
            ->whereNotIn('status', [Election::STATUS_FINAL, Election::STATUS_CANCELLED])
            ->first();

        if ($open !== null) {
            return (string) $open->id; // already curing — extend = no-op (flagged minimal)
        }

        $result = app(\App\Domain\Engine\ConstitutionalEngine::class)->file('F-ORG-004', null, [
            'action'   => 'open_worker_election',
            'board_id' => (string) $board->id,
            'seats'    => $seats,
        ]);

        return $result->recorded['election_id'] ?? null;
    }

    // =========================================================================
    // Validity gate (consumed by ChamberVoteService::open for BODY_BOARD)
    // =========================================================================

    /**
     * Hardened enforcement posture: an INVALID board cannot open a board
     * vote except the cure path (Art. III §6). Already-open votes close
     * normally — snapshot-at-open discipline.
     */
    public static function assertBoardMayOpenVote(Board $board, string $voteType): void
    {
        if (! $board->composition_valid && ! in_array($voteType, self::CURE_VOTE_TYPES, true)) {
            throw new ConstitutionalViolation(
                'This board\'s composition is invalid — required worker-elected seats are unfilled. '
                . 'The board cannot act until the curing election seats them (the cure path stays open).',
                'Art. III §6'
            );
        }
    }

    // =========================================================================
    // Joint chair (§C.3)
    // =========================================================================

    /**
     * Composition change → chair cleared → fresh board_chair_elect RCV
     * vote by the FULL board (every seated seat, one lane, equal votes).
     */
    public function openChairElection(Board $board): ChamberVote
    {
        if ($board->chair_seat_id !== null) {
            BoardSeat::query()->whereKey($board->chair_seat_id)->update(['is_chair' => false]);
            $board->forceFill(['chair_seat_id' => null])->save();
        }

        $seated = $board->seats()->seated()->count();

        if ($seated < 2) {
            throw new ConstitutionalViolation(
                'A joint chair election needs at least two seated board members.',
                'Art. III §6 · as implemented'
            );
        }

        return app(ChamberVoteService::class)->open(
            bodyType: ChamberVote::BODY_BOARD,
            bodyId: (string) $board->id,
            voteType: 'board_chair_elect',
            votable: $board,
        );
    }

    /**
     * Votable arm 'board' — chair vote close (ChamberVoteService
     * dispatch, same transaction). Winner (a board_seats id) becomes the
     * joint chair; no majority winner → the vote closed failed and a
     * re-ballot is the board's next move (the engine never auto-loops).
     */
    public function resolveChairVote(ChamberVote $vote, string $outcome): void
    {
        $board = Board::query()->find($vote->votable_id ?? $vote->body_id);

        if ($board === null) {
            return;
        }

        if ($outcome !== ChamberVote::OUTCOME_ADOPTED) {
            return;
        }

        $winnerSeatId = $vote->rcv_record['winner_member_id'] ?? null;

        if ($winnerSeatId === null) {
            return;
        }

        $seat = BoardSeat::query()->whereKey($winnerSeatId)->first();

        if ($seat === null || (string) $seat->board_id !== (string) $board->id || $seat->status !== BoardSeat::STATUS_SEATED) {
            return;
        }

        BoardSeat::query()->where('board_id', $board->id)->where('is_chair', true)->update(['is_chair' => false]);

        $seat->forceFill(['is_chair' => true])->save();
        $board->forceFill(['chair_seat_id' => (string) $seat->id])->save();

        if ($board->status === Board::STATUS_FORMING) {
            $board->forceFill(['status' => Board::STATUS_ACTIVE])->save();
        }

        $this->records->publish(
            kind: 'certification',
            title: 'Board joint chair elected',
            body: sprintf(
                'Seat %d (%s) elected joint chair of board %s by RCV of the full board — majority of all seated members (Art. III §6).',
                (int) $seat->seat_no,
                $seat->seat_class,
                (string) $board->id
            ),
            attrs: [
                'actor_user_id'   => $seat->holder_user_id !== null ? (string) $seat->holder_user_id : null,
                'jurisdiction_id' => $board->jurisdictionId(),
                'via_workflow'    => 'WF-ORG-05',
                'subject_type'    => 'boards',
                'subject_id'      => (string) $board->id,
            ],
        );

        if ($seat->holder_user_id !== null) {
            $this->roles->flushUser((string) $seat->holder_user_id); // R-28
        }
    }

    /**
     * Composition-change hook (§B.3.5): seat-count or seat-holder change
     * in either class clears the chair and re-opens the chair election
     * when the board can hold one.
     */
    public function onCompositionChange(Board $board): ?ChamberVote
    {
        try {
            return $this->openChairElection($board->refresh());
        } catch (ConstitutionalViolation) {
            // Fewer than two seated members — the chair election waits
            // for the next seating.
            return null;
        }
    }
}
