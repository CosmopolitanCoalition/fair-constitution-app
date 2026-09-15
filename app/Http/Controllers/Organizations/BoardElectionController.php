<?php

namespace App\Http\Controllers\Organizations;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CandidacyPanel;
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
use App\Models\VoteCast;
use App\Services\Organizations\OrgSettingsService;
use App\Services\Rooms\BoardRoomAccess;
use App\Support\CgcGovernorWorkspace;
use App\Support\GovernorNomineeDirectory;
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
    private array $holderNames = [];

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly StvRoundPresenter $stv,
        private readonly ChamberVotePresenter $votes,
    ) {}

    public function show(\Illuminate\Http\Request $request, Organization $organization): Response
    {
        $this->holderNames = [];
        $board = $organization->board_id !== null
            ? Board::query()->with('seats.holder:id,display_name')->find($organization->board_id)
            : null;

        $viewerIsAgent = $request->user() !== null
            && (string) $organization->agent_user_id === (string) $request->user()->getKey();
        $canAdminister = $viewerIsAgent && $organization->status === Organization::STATUS_ACTIVE;
        $boardIsCurrent = $board !== null && $board->status !== Board::STATUS_DISSOLVED
            && $board->boardable_type === Board::BOARDABLE_ORGANIZATIONS
            && (string) $board->boardable_id === (string) $organization->id;
        $vacancies = $board?->seats->where('status', BoardSeat::STATUS_VACANT);
        $governors = new CgcGovernorWorkspace($organization, $board, $request->user(), $this->votes);
        $appointmentContext = $governors->context();
        $appointmentDirectory = null;
        $appointmentPage = function () use (&$appointmentDirectory, $governors, $request) {
            return $appointmentDirectory ??= $governors->appointments($request);
        };

        return Inertia::render('Organizations/BoardElections', [
            'surface' => SurfaceMeta::for('organizations/board-elections'),
            'organization' => $this->header($organization, $board),
            'composition' => $this->composition($board),
            // The open-nomination window DIAL (operator v3.2 item 0d) — the
            // org's own setting, read and linked here (it is set on org detail).
            'nominationWindow' => $this->nominationWindow($organization),
            'appointmentContext' => $appointmentContext,
            'governorAppointments' => fn () => $appointmentPage()['rows'],
            'governorPages' => fn () => $appointmentPage()['pages'],
            'nomineeDirectory' => Inertia::optional(fn () => ($appointmentContext['canNominate'] ?? false)
                ? app(GovernorNomineeDirectory::class)->page($request, (string) $organization->id, (string) $organization->jurisdiction_id)
                : GovernorNomineeDirectory::empty()),
            'ownerTrack' => $this->track(
                $organization,
                $board,
                Election::KIND_ORG_BOARD_OWNER,
                ElectionRace::ELECTORATE_OWNERS,
                $canAdminister && $boardIsCurrent && ! $organization->is_cgc,
            ),
            'workerTrack' => $this->track(
                $organization,
                $board,
                Election::KIND_ORG_BOARD_WORKER,
                ElectionRace::ELECTORATE_WORKERS,
                $canAdminister && $boardIsCurrent,
            ),
            'chair' => $this->chair($board, $boardIsCurrent && $organization->status === Organization::STATUS_ACTIVE ? $request->user() : null),
            'seated' => $this->seated($board),
            'roomHref' => $board && app(BoardRoomAccess::class)->allows($request->user(), $board)
                ? '/rooms/board/'.$board->id : null,
            'can' => [
                // The same R-23 gate the F-ORG-003/004 handlers enforce; the
                // engine re-asserts on POST — the UI flag is UX only. Worker
                // track also fires system-side from CLK-13 (never blocked by
                // a missing agent).
                'provisionBoard' => $canAdminister && ! $organization->is_cgc && $organization->board_id === null,
                'administerOwner' => $canAdminister && $boardIsCurrent && ! $organization->is_cgc
                    && $vacancies->contains('seat_class', BoardSeat::CLASS_OWNER_ELECTED),
                'administerWorker' => $canAdminister && $boardIsCurrent
                    && (int) $board->worker_seats > 0
                    && $vacancies->contains('seat_class', BoardSeat::CLASS_WORKER_ELECTED),
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
            'election_id' => ['required_if:action,certify', 'nullable', 'uuid'],
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

            return back()->with('status', match ($validated['action']) {
                'provision_board' => __('Board established. Its seats can now be filled through the applicable elections.'),
                'open_owner_election' => __('Owner-seat election opened. Eligible owners or members can now nominate candidates and vote.'),
                default => __('Owner-seat election result certified.'),
            });
        }

        // F-ORG-004 keys on the board (open worker / certify). The worker
        // track also fires system-side from CLK-13 — R-23 absence never
        // stalls a constitutionally-required seat.
        $board = $organization->board_id !== null ? Board::query()->find($organization->board_id) : null;

        if ($board === null) {
            return back()->withErrors([
                'constitution' => __('This organization has no board yet — provision the board on the owner track first (F-ORG-003).'),
            ]);
        }

        $this->engine->file('F-ORG-004', $request->user(), array_filter([
            'board_id' => (string) $board->id,
            'action' => $validated['action'],
            'election_id' => $validated['election_id'] ?? null,
        ], fn ($v) => $v !== null));

        return back()->with('status', $validated['action'] === 'certify'
            ? __('Worker-seat election result certified.')
            : __('Worker-seat election opened. Eligible workers can now nominate candidates and vote.'));
    }

    // =========================================================================
    // Prop builders — all read straight off the rows
    // =========================================================================

    /** The route selects the organization; no actor or target override comes from the browser. */
    public function nominateGovernor(\Illuminate\Http\Request $request, Organization $organization): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user(), 403);
        $data = $request->validate(['nominee_user_id' => ['required', 'uuid'], 'dossier' => ['nullable', 'string', 'max:20000']]);
        $this->engine->file('F-EXE-001', $request->user(), [
            'organization_id' => (string) $organization->id, 'jurisdiction_id' => (string) $organization->jurisdiction_id,
            'nominee_user_id' => $data['nominee_user_id'], 'dossier' => $data['dossier'] ?? null,
        ]);

        return redirect('/organizations/'.$organization->id.'/board-elections#governor-appointments')
            ->with('status', __('Governor nominated. The dossier is public and the creating legislature’s consent vote is open below.'));
    }

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
    private function track(Organization $organization, ?Board $board, string $kind, string $electorate, bool $mayCertify): array
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
        $certificationReady = $this->readyToCertify($election);

        return [
            'form' => $form,
            'electorate_type' => $electorate,
            'electorate_count' => $this->electorateCount($organization, $electorate),
            'exists' => $workerTrackExists || $election !== null,
            'certificationReady' => $certificationReady,
            'canCertify' => $mayCertify && $certificationReady,
            'election' => $election !== null ? [
                'id' => (string) $election->id,
                'status' => $election->status,
                'live' => ! in_array($election->status, [
                    Election::STATUS_FINAL, Election::STATUS_CANCELLED,
                ], true),
                'href' => '/elections/'.$election->id,
            ] : null,
            'result' => $election !== null ? $this->result($election) : null,
            // The open-nomination window phase for THIS track's election —
            // dates straight off the election row (approval_opens → finalist
            // cutoff = nominations; ranked open → close = ranking), and the
            // current node mapped from election.status. Null until it opens.
            'nomination' => $election !== null ? [
                'nominations_open_at' => $election->approval_opens_at?->toIso8601String(),
                'nominations_close_at' => $election->finalist_cutoff_at?->toIso8601String(),
                'ranking_open_at' => $election->ranked_opens_at?->toIso8601String(),
                'ranking_close_at' => $election->ranked_closes_at?->toIso8601String(),
                'phase' => $this->windowPhase($election),
            ] : null,
            // Worker-track provenance (§B.9): the scale (CLK-14) sets the
            // seat count; the first seat appears at the CLK-13 minimum.
            'trigger' => $kind === Election::KIND_ORG_BOARD_WORKER && $board !== null && (int) $board->worker_seats > 0
                ? 'scale'
                : null,
        ];
    }

    /** Readiness mirrors the seating service's state and complete, sealed count checks. */
    private function readyToCertify(?Election $election): bool
    {
        if ($election === null || ! in_array($election->status, [
            Election::STATUS_VOTING_CLOSED, Election::STATUS_TABULATING,
        ], true)) {
            return false;
        }

        // Exists queries stay scoped to this election; never materialize all races or count records.
        return $election->races()->exists()
            && ! $election->races()->whereDoesntHave('tabulations', fn ($query) => $query
                ->where('status', Tabulation::STATUS_COMPLETE)
                ->whereNotNull('record_hash'))
                ->exists();
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
    private function chair(?Board $board, ?User $viewer = null): ?array
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
            ->orderByDesc('id')
            ->first();

        $seatedCount = $board->seats->where('status', BoardSeat::STATUS_SEATED)->count();
        $viewerSeats = $viewer === null ? collect() : $board->seats
            ->where('status', BoardSeat::STATUS_SEATED)->where('holder_user_id', $viewer->id);
        $casts = $vote && $viewerSeats->isNotEmpty() ? VoteCast::query()->where('vote_id', $vote->id)
            ->whereIn('board_seat_id', $viewerSeats->pluck('id'))->get()->keyBy('board_seat_id') : collect();
        $memberSeats = $viewerSeats->map(fn (BoardSeat $seat) => [
            'id' => (string) $seat->id, 'seatNumber' => (int) $seat->seat_no, 'seatClass' => $seat->seat_class,
            'submitted' => $casts->has($seat->id), 'rankings' => $casts->get($seat->id)?->rankings ?? [],
        ])->values()->all();
        $open = $vote?->status === ChamberVote::STATUS_OPEN;

        return [
            'id' => $vote?->id,
            'status' => $vote?->status,
            'canOpen' => $viewerSeats->isNotEmpty() && $board->chair_seat_id === null && ! $open && $seatedCount >= 2,
            'canCast' => $viewerSeats->count() > $casts->count() && $board->chair_seat_id === null && $open,
            'submitted' => $casts->isNotEmpty(),
            'memberSeats' => $memberSeats,
            'candidates' => $board->seats->where('status', BoardSeat::STATUS_SEATED)->sortBy('seat_no')
                ->map(fn (BoardSeat $seat) => ['id' => (string) $seat->id, 'name' => $this->holderName($seat),
                    'seatNumber' => (int) $seat->seat_no, 'seatClass' => $seat->seat_class])->values()->all(),
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

    public function chairAction(\Illuminate\Http\Request $request, Organization $organization): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:open,cast'],
            'vote_id' => ['required_if:action,cast', 'nullable', 'uuid'],
            'board_seat_id' => ['required_if:action,cast', 'nullable', 'uuid'],
            'rankings' => ['required_if:action,cast', 'array', 'min:1'],
            'rankings.*' => ['required', 'uuid', 'distinct'],
            'explanation' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless($request->user(), 403);
        $this->engine->file('F-ORG-010', $request->user(), $validated + [
            'organization_id' => (string) $organization->id,
            'jurisdiction_id' => (string) $organization->jurisdiction_id,
        ]);

        return back()->with('status', $validated['action'] === 'open' ? __('Chair ballot is open.') : __('Your chair ranking has been recorded.'));
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
        $seat->loadMissing('holder:id,display_name');
        $user = $seat->holder;

        return $user === null ? __('Seated member') : ($this->holderNames[$user->id] ??= CandidacyPanel::displayName($user));
    }

    /**
     * The open-nomination window DIAL (operator v3.2 item 0d): an org-level
     * setting — N days of nomination before ranking opens — read straight
     * off the org's own settings. NULL window_days = the jurisdiction's
     * default election schedule stands (honest absence). The 1–90 bound is
     * the setting's own validation range; the dial itself is set on the org
     * detail page (role-gated to the agent), so this surface only reads it
     * and links there. Never a constitutional value.
     *
     * @return array<string, mixed>
     */
    private function nominationWindow(Organization $organization): array
    {
        $days = app(OrgSettingsService::class)->get($organization, 'board_nomination_window_days');

        return [
            'window_days' => $days === null ? null : (int) $days,
            'is_set' => $days !== null,
            'min' => 1,
            'max' => 90,
            'settings_href' => '/organizations/'.$organization->id,
        ];
    }

    /**
     * Which node of the nomination → ranking → count strip an election sits
     * in, mapped from the engine's election.status snapshot (never a clock
     * computation here). The state strip highlights this node.
     */
    private function windowPhase(Election $election): string
    {
        return match ($election->status) {
            Election::STATUS_SCHEDULED,
            Election::STATUS_APPROVAL_OPEN,
            Election::STATUS_FINALIST_CUTOFF => 'nominations',
            Election::STATUS_RANKED_OPEN,
            Election::STATUS_VOTING_CLOSED => 'ranking',
            default => 'count',
        };
    }
}
