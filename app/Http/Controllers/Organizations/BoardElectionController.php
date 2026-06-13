<?php

namespace App\Http\Controllers\Organizations;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ChamberVotePresenter;
use App\Http\Presenters\StvRoundPresenter;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\Organization;
use App\Models\OrgMembership;
use App\Models\OrgWorker;
use App\Models\Tabulation;
use App\Models\User;
use App\Support\SurfaceMeta;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-D8 — Board elections (PHASE_D_DESIGN_frontend.md §B.9; surface
 * organizations/board-elections).
 *
 *   GET /organizations/{organization}/board-elections — the owner / worker
 *       STV tracks (the same Phase B election machinery — elections.kind
 *       org_board_owner|org_board_worker, races.electorate_type
 *       owners|workers), the joint chair RCV card (body_type='board',
 *       full-board majority off chamber_votes), and the seated board.
 *
 * Public read (board-election counts publish like every election —
 * Art. II §2); administration is gated R-23 (the org agent), voting
 * happens on the Phase B ballot surfaces (link out, never a forked
 * ballot UI).
 *
 * PURE READER of engine snapshots: every seat count, worker_seats,
 * composition_valid, the Droop quota, and the chair vote's required_yes /
 * board size come off rows (boards / board_seats / tabulations /
 * chamber_vote_tallies) — this controller NEVER computes a threshold.
 */
class BoardElectionController extends Controller
{
    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly StvRoundPresenter $stv,
        private readonly ChamberVotePresenter $votes,
    ) {}

    public function show(\Illuminate\Http\Request $request, Organization $organization): Response
    {
        $board = $organization->board_id !== null
            ? Board::query()->with('seats')->find($organization->board_id)
            : null;

        $viewerIsAgent = $request->user() !== null
            && (string) $organization->agent_user_id === (string) $request->user()->getKey();

        return Inertia::render('Organizations/BoardElections', [
            'surface' => SurfaceMeta::for('organizations/board-elections'),
            'organization' => $this->header($organization, $board),
            'composition' => $this->composition($board),
            'ownerTrack' => $this->track(
                $organization,
                $board,
                Election::KIND_ORG_BOARD_OWNER,
                ElectionRace::ELECTORATE_OWNERS,
            ),
            'workerTrack' => $this->track(
                $organization,
                $board,
                Election::KIND_ORG_BOARD_WORKER,
                ElectionRace::ELECTORATE_WORKERS,
            ),
            'chair' => $this->chair($board),
            'seated' => $this->seated($board),
            'can' => [
                // The same R-23 gate the F-ORG-003/004 handlers enforce; the
                // engine re-asserts on POST — the UI flag is UX only. Worker
                // track also fires system-side from CLK-13 (never blocked by
                // a missing agent).
                'administerOwner' => $viewerIsAgent,
                'administerWorker' => $viewerIsAgent,
            ],
        ]);
    }

    /**
     * POST /organizations/{organization}/board-elections — administer a
     * board election (R-23). Owner track files F-ORG-003, worker track
     * F-ORG-004; both run through the engine, which re-asserts the R-23
     * agent gate and the constitutional rules (the UI flag is UX only).
     * A ConstitutionalViolation surfaces as the 422 citation Banner
     * (rendered globally → back()->withErrors(['constitution' => …])).
     */
    public function store(\Illuminate\Http\Request $request, Organization $organization): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'track' => ['required', 'string', 'in:owner,worker'],
            'action' => ['required', 'string', 'in:provision_board,open_owner_election,open_worker_election,certify'],
            'owner_seats' => ['nullable', 'integer', 'min:1', 'max:99'],
            'cycle_months' => ['nullable', 'integer', 'min:1'],
            'election_id' => ['nullable', 'uuid'],
        ]);

        if ($validated['track'] === 'owner') {
            // F-ORG-003 keys on the organization (provision / open owner / certify).
            $this->engine->file('F-ORG-003', $request->user(), array_filter([
                'organization_id' => (string) $organization->id,
                'action' => $validated['action'],
                'owner_seats' => $validated['owner_seats'] ?? null,
                'cycle_months' => $validated['cycle_months'] ?? null,
                'election_id' => $validated['election_id'] ?? null,
            ], fn ($v) => $v !== null));

            return back()->with('status', 'Owner-track board election administered (F-ORG-003) — counts publish on the public ballot surfaces · Art. III §4, §6.');
        }

        // F-ORG-004 keys on the board (open worker / certify). The worker
        // track also fires system-side from CLK-13 — R-23 absence never
        // stalls a constitutionally-required seat.
        $board = $organization->board_id !== null ? Board::query()->find($organization->board_id) : null;

        if ($board === null) {
            return back()->withErrors([
                'constitution' => 'This organization has no board yet — provision the board on the owner track first (F-ORG-003).',
            ]);
        }

        $this->engine->file('F-ORG-004', $request->user(), array_filter([
            'board_id' => (string) $board->id,
            'action' => $validated['action'],
            'election_id' => $validated['election_id'] ?? null,
        ], fn ($v) => $v !== null));

        return back()->with('status', 'Worker-track board election administered (F-ORG-004) — the worker seats come from the uniform co-determination scale · Art. III §6.');
    }

    // =========================================================================
    // Prop builders — all read straight off the rows
    // =========================================================================

    /** @return array<string, mixed> */
    private function header(Organization $organization, ?Board $board): array
    {
        return [
            'id' => (string) $organization->id,
            'name' => $organization->name,
            'type' => $organization->type,
            'structure' => $organization->structure,
            'is_cgc' => (bool) $organization->is_cgc,
            'status' => $organization->status,
            'has_board' => $board !== null,
            'detail_href' => '/organizations/'.$organization->id,
            'codet_href' => '/organizations/co-determination?org='.$organization->id,
        ];
    }

    /**
     * composition.* — every number an ENGINE snapshot off the boards row
     * (owner_seats / worker_seats / composition_valid) plus the seated
     * chair holder, never recomputed (§B.9).
     *
     * @return array<string, mixed>|null
     */
    private function composition(?Board $board): ?array
    {
        if ($board === null) {
            return null;
        }

        $chairSeat = $board->chair_seat_id !== null
            ? $board->seats->firstWhere('id', $board->chair_seat_id)
            : null;

        return [
            'ownerSeats' => (int) $board->owner_seats,
            'workerSeats' => (int) $board->worker_seats,           // the scale's number — engine output
            'requiredWorkerSeats' => (int) $board->worker_seats,           // what the scale demands == worker_seats
            'compositionValid' => (bool) $board->composition_valid,     // boards.composition_valid
            'chair' => $chairSeat?->holder_user_id !== null
                ? ['name' => $this->holderName($chairSeat)]
                : null,
        ];
    }

    /**
     * One track (owner or worker): the live/last election (→ Phase B
     * surfaces), the certified STV result (final-round StvBar rows + the
     * Droop line — straight off the certified tabulation), the electorate
     * count, and the worker track's CLK-13/scale provenance.
     *
     * @return array<string, mixed>
     */
    private function track(Organization $organization, ?Board $board, string $kind, string $electorate): array
    {
        $form = $kind === Election::KIND_ORG_BOARD_OWNER ? 'F-ORG-003' : 'F-ORG-004';

        // Worker track does not exist below the first-seat threshold — the
        // honest empty state ("No worker track — first seat at {min}
        // workers") is rendered by the page when this is false.
        $workerTrackExists = $kind === Election::KIND_ORG_BOARD_OWNER
            || ($board !== null && (int) $board->worker_seats > 0);

        $election = $board === null ? null : Election::query()
            ->where('board_id', $board->id)
            ->where('kind', $kind)
            ->orderByDesc('created_at')
            ->first();

        return [
            'form' => $form,
            'electorate_type' => $electorate,
            'electorate_count' => $this->electorateCount($organization, $electorate),
            'exists' => $workerTrackExists,
            'election' => $election !== null ? [
                'id' => (string) $election->id,
                'status' => $election->status,
                'live' => ! in_array($election->status, [
                    Election::STATUS_FINAL, Election::STATUS_CANCELLED,
                ], true),
                'href' => '/elections/'.$election->id,
            ] : null,
            'result' => $election !== null ? $this->result($election) : null,
            // Worker-track provenance (§B.9): the scale (CLK-14) sets the
            // seat count; the first seat appears at the CLK-13 minimum.
            'trigger' => $kind === Election::KIND_ORG_BOARD_WORKER && $board !== null && (int) $board->worker_seats > 0
                ? 'scale'
                : null,
        ];
    }

    /**
     * The certified STV record for the election's first race, via the
     * shared StvRoundPresenter (§C contract). Null until a complete
     * tabulation exists. The page renders the final round's StvBar rows +
     * the Droop quota line; nothing computed here.
     *
     * @return array<string, mixed>|null
     */
    private function result(Election $election): ?array
    {
        $race = $election->races()->orderBy('created_at')->first();

        if ($race === null) {
            return null;
        }

        $tabulation = Tabulation::query()
            ->where('race_id', (string) $race->id)
            ->where('kind', Tabulation::KIND_INITIAL)
            ->complete()
            ->whereNotNull('record_hash')
            ->orderByDesc('completed_at')
            ->first();

        if ($tabulation === null) {
            return null;
        }

        return $this->stv->present($tabulation) + [
            'certified_at' => $election->certified_at?->toIso8601String(),
        ];
    }

    /**
     * The joint chair card (§B.9): the board RCV vote (body_type='board',
     * full-board majority) as VoteTally props + the round-by-round record,
     * plus the pending re-election reason when composition has changed.
     *
     * @return array<string, mixed>|null
     */
    private function chair(?Board $board): ?array
    {
        if ($board === null) {
            return null;
        }

        $vote = ChamberVote::query()
            ->with('tallies')
            ->where('body_type', ChamberVote::BODY_BOARD)
            ->where('body_id', (string) $board->id)
            ->where('vote_type', 'board_chair_elect')
            ->orderByDesc('opened_at')
            ->first();

        $seatedCount = $board->seats->where('status', BoardSeat::STATUS_SEATED)->count();

        return [
            'vote' => $vote !== null ? $this->votes->tallyProps($vote) : null,
            // The full-board threshold + size — engine snapshots off the
            // chamber_vote row (serving_snapshot) / its lane (required_yes).
            'required' => $vote?->tallies->first()?->required_yes !== null
                ? (int) $vote->tallies->first()->required_yes
                : null,
            'board_size' => $vote !== null ? (int) $vote->serving_snapshot : $seatedCount,
            'rounds' => $vote !== null ? $this->votes->rcvRounds($vote) : null,
            // Composition changed → a fresh chair election is required before
            // the board acts (§C.3): chair cleared, board still has a quorum.
            'pending_reason' => $board->chair_seat_id === null && $seatedCount >= 2
                ? 'composition_changed'
                : null,
        ];
    }

    /**
     * The seated board — BoardStrip props (§A.3). Same rows, same
     * composition_valid flag as every other Phase D board render.
     *
     * @return array<string, mixed>|null
     */
    private function seated(?Board $board): ?array
    {
        if ($board === null) {
            return null;
        }

        return [
            'compositionValid' => (bool) $board->composition_valid,
            'requiredWorkerSeats' => (int) $board->worker_seats,
            'seats' => $board->seats
                ->sortBy('seat_no')
                ->map(fn (BoardSeat $seat) => [
                    'id' => (string) $seat->id,
                    'seat_class' => $seat->seat_class,
                    'holder' => $seat->holder_user_id !== null
                        ? ['name' => $this->holderName($seat)]
                        : null,
                    'is_chair' => (bool) $seat->is_chair,
                    'status' => $seat->status,
                    'term' => $this->termRow($seat),
                ])
                ->values()
                ->all(),
        ];
    }

    // =========================================================================
    // Small readers
    // =========================================================================

    /**
     * Live electorate size for a track: active shareholders (owner track —
     * the membership class an org's structure accepts) / active workers
     * (worker track — the F-IND-014 headcount feed). Counts only, never a
     * threshold.
     */
    private function electorateCount(Organization $organization, string $electorate): int
    {
        if ($electorate === ElectionRace::ELECTORATE_WORKERS) {
            return OrgWorker::query()
                ->where('employer_type', OrgWorker::EMPLOYER_ORGANIZATIONS)
                ->where('employer_id', (string) $organization->id)
                ->where('status', OrgWorker::STATUS_ACTIVE)
                ->count();
        }

        return OrgMembership::query()
            ->where('organization_id', (string) $organization->id)
            ->where('status', OrgMembership::STATUS_ACTIVE)
            ->when(
                $organization->membershipKind() !== null,
                fn ($q) => $q->where('kind', $organization->membershipKind()),
            )
            ->count();
    }

    /** @return array<string, string|null>|null */
    private function termRow(BoardSeat $seat): ?array
    {
        $seat->loadMissing('term');

        if ($seat->term === null) {
            return null;
        }

        return [
            'starts_on' => $seat->term->starts_on?->toDateString(),
            'ends_on' => $seat->term->ends_on?->toDateString(),
            // Governor / owner-elected seats run the 10-yr civil clock
            // (CLK-09); worker seats end with the org cycle (CLK-10).
            'clock' => $seat->seat_class === BoardSeat::CLASS_WORKER_ELECTED ? 'CLK-10' : 'CLK-09',
        ];
    }

    private function holderName(BoardSeat $seat): string
    {
        $user = $seat->holder_user_id !== null
            ? User::query()->find($seat->holder_user_id)
            : null;

        return $user?->display_name ?: ($user?->name ?? 'Seated member');
    }
}
