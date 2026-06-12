<?php

namespace App\Http\Controllers\Elections;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Forms\Support\RaceFootprint;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Elections\Concerns\ResolvesBoardActor;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\ElectionAudit;
use App\Models\ElectionBoard;
use App\Models\ElectionCertification;
use App\Models\ElectionRace;
use App\Models\LegislatureMember;
use App\Models\Tabulation;
use App\Models\Vacancy;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-B7 — BoardConsole (PHASE_B_DESIGN_frontend.md §B.7).
 *
 *   GET  /board                            — show (gate: access-board, R-08)
 *   POST /board/scheduling-orders          — schedule          (F-ELB-001)
 *   POST /board/validations/{candidacy}    — decideValidation  (F-ELB-002)
 *   POST /elections/{election}/certify     — certify           (F-ELB-004)
 *   POST /elections/{election}/recount     — recount           (F-ELB-006)
 *
 * Every write goes through ConstitutionalEngine::file — the controller
 * resolves WHICH actor posture applies (seated member files as
 * themselves; the operator drives a bootstrap board as a system filing —
 * see ResolvesBoardActor) and the engine enforces everything else.
 */
class BoardConsoleController extends Controller
{
    use ResolvesBoardActor;

    public function __construct(
        private readonly ConstitutionalEngine $engine,
    ) {
    }

    public function show(Request $request): Response
    {
        Gate::authorize('access-board');

        $user = $request->user();
        $userId = (string) $user->getKey();

        $boards = ElectionBoard::query()
            ->active()
            ->where(function ($query) use ($userId, $user) {
                $query->whereHas('members', fn ($m) => $m->seated()->where('user_id', $userId));

                if ((bool) $user->is_operator) {
                    $query->orWhere('is_bootstrap', true);
                }
            })
            ->with(['jurisdiction:id,name', 'members.user:id,name,display_name'])
            ->get()
            ->sortBy(fn (ElectionBoard $b) => $b->jurisdiction?->name ?? '')
            ->values();

        abort_if($boards->isEmpty(), 403, 'No active election board standing.');

        $board = $boards->firstWhere('id', $request->query('board')) ?? $boards->first();

        $elections = Election::query()
            ->where(function ($query) use ($board) {
                $query->where('election_board_id', (string) $board->id)
                    ->orWhere(fn ($q) => $q->whereNull('election_board_id')
                        ->where('jurisdiction_id', (string) $board->jurisdiction_id));
            })
            ->with(['jurisdiction:id,name', 'races'])
            ->orderBy('created_at')
            ->get();

        $open = $elections->reject(fn (Election $e) => in_array(
            $e->status,
            [Election::STATUS_FINAL, Election::STATUS_CANCELLED],
            true,
        ))->values();

        $pendingValidation = Candidacy::query()
            ->whereIn('election_id', $open->pluck('id')->map(fn ($id) => (string) $id))
            ->where('status', Candidacy::STATUS_REGISTERED)
            ->with('user:id,name,display_name')
            ->orderBy('created_at')
            ->get();

        $vacancies = Vacancy::query()
            ->where('jurisdiction_id', (string) $board->jurisdiction_id)
            ->orderByDesc('declared_at')
            ->get();

        return Inertia::render('Elections/BoardConsole', [
            'surface' => SurfaceMeta::for('elections/board-console'),
            'board' => [
                'id'                => (string) $board->id,
                'jurisdiction_name' => $board->jurisdiction?->name,
                'is_bootstrap'      => (bool) $board->is_bootstrap,
                'status'            => $board->status,
                'members'           => $board->members
                    ->map(fn ($member) => [
                        'name' => $member->user !== null
                            ? ($member->user->display_name ?: $member->user->name)
                            : 'The system (bootstrap board)',
                    ])
                    ->all(),
            ],
            'boards' => $boards
                ->map(fn (ElectionBoard $b) => [
                    'id'                => (string) $b->id,
                    'jurisdiction_name' => $b->jurisdiction?->name,
                    'is_bootstrap'      => (bool) $b->is_bootstrap,
                ])
                ->all(),
            'stats' => [
                'electionsAdministered' => $open->count(),
                'validationsPending'    => $pendingValidation->count(),
                'countbacksRunning'     => $vacancies->where('status', Vacancy::STATUS_COUNTBACK_RUNNING)->count(),
                'petitionAuditsDue'     => 0, // petitions are Phase C
            ],
            'schedulable' => $open
                ->filter(fn (Election $e) => in_array(
                    $e->status,
                    [Election::STATUS_SCHEDULED, Election::STATUS_APPROVAL_OPEN],
                    true,
                ))
                ->values()
                ->map(fn (Election $e) => [
                    'election_id'        => (string) $e->id,
                    'label'              => $this->electionLabel($e),
                    'finalist_cutoff_at' => $e->finalist_cutoff_at?->toIso8601String(),
                    'ranked_opens_at'    => $e->ranked_opens_at?->toIso8601String(),
                    'ranked_closes_at'   => $e->ranked_closes_at?->toIso8601String(),
                    'approval_opens_at'  => $e->approval_opens_at?->toIso8601String(),
                    'races'              => $e->races
                        ->map(fn (ElectionRace $r) => [
                            'label'          => $this->raceLabel($r),
                            'finalist_count' => (int) $r->finalist_count,
                        ])
                        ->all(),
                ])
                ->all(),
            'validationQueue' => $pendingValidation
                ->map(fn (Candidacy $candidacy) => $this->queueRow($candidacy, $elections))
                ->all(),
            'districtOversight' => $this->districtOversight($board),
            'certifiable' => $open
                ->filter(fn (Election $e) => in_array($e->status, [
                    Election::STATUS_VOTING_CLOSED,
                    Election::STATUS_TABULATING,
                    Election::STATUS_CERTIFIED,
                    Election::STATUS_AUDIT_RERUN,
                ], true))
                ->values()
                ->map(fn (Election $e) => $this->certifiableRow($e))
                ->all(),
            'petitionAudits' => [], // F-ELB-005 panel chrome ships; petitions are Phase C
            'vacancies' => $vacancies
                ->map(fn (Vacancy $vacancy) => [
                    'vacancy_id' => (string) $vacancy->id,
                    'label'      => $this->vacancyLabel($vacancy),
                    'status'     => $vacancy->status,
                ])
                ->all(),
        ]);
    }

    /** F-ELB-001 — refine an election's schedule (dates within bounds). */
    public function schedule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'election_id'        => ['required', 'uuid'],
            'finalist_cutoff_at' => ['required', 'date'],
            'ranked_opens_at'    => ['required', 'date'],
            'ranked_closes_at'   => ['required', 'date'],
        ]);

        $election = Election::query()->findOrFail($validated['election_id']);
        $actor = $this->requireBoardStanding($request, $election);

        $this->engine->file('F-ELB-001', $actor, [
            'election_id'        => (string) $election->id,
            'jurisdiction_id'    => (string) $election->jurisdiction_id,
            'finalist_cutoff_at' => $validated['finalist_cutoff_at'],
            'ranked_opens_at'    => $validated['ranked_opens_at'],
            'ranked_closes_at'   => $validated['ranked_closes_at'],
        ]);

        return back()->with('status', 'Scheduling order issued — X pre-published per race (CLK-21).');
    }

    /** F-ELB-002 — validate/reject one queue row (residency is the only check). */
    public function decideValidation(Request $request, Candidacy $candidacy): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:validate,reject'],
        ]);

        $election = Election::query()->findOrFail((string) $candidacy->election_id);
        $actor = $this->requireBoardStanding($request, $election);

        $this->engine->file('F-ELB-002', $actor, [
            'candidacy_id'    => (string) $candidacy->id,
            'decision'        => $validated['decision'],
            'jurisdiction_id' => (string) $election->jurisdiction_id,
        ]);

        return back()->with('status', $validated['decision'] === 'validate'
            ? 'Validated — the candidate is in the approval pool.'
            : 'Rejected — no residency association found; the appeal path is open (Art. I).');
    }

    /** F-ELB-004 — certify the election (winners granted roles). */
    public function certify(Request $request, Election $election): RedirectResponse
    {
        $actor = $this->requireBoardStanding($request, $election);

        $this->engine->file('F-ELB-004', $actor, [
            'election_id'     => (string) $election->id,
            'jurisdiction_id' => (string) $election->jurisdiction_id,
        ]);

        return back()->with('status', 'Certified — winners granted roles; the next cycle\'s approval phase is open.');
    }

    /** F-ELB-006 — order an audit re-run (cause required; engine rejects empty). */
    public function recount(Request $request, Election $election): RedirectResponse
    {
        $validated = $request->validate([
            'cause' => ['required', 'string', 'max:2000'],
        ]);

        $actor = $this->requireBoardStanding($request, $election);

        $this->engine->file('F-ELB-006', $actor, [
            'election_id'     => (string) $election->id,
            'cause'           => $validated['cause'],
            'jurisdiction_id' => (string) $election->jurisdiction_id,
        ]);

        return back()->with('status', 'Audit re-run ordered — tabulation re-runs from the stored ballots (no hand count).');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return \App\Models\User|null the engine actor (null = system-as-bootstrap-board) */
    private function requireBoardStanding(Request $request, Election $election)
    {
        $board = $this->activeBoardFor($election->election_board_id, $election->jurisdiction_id);
        $standing = $this->boardActorFor($request->user(), $board);

        abort_if($standing === false, 403, 'This filing requires standing on the election\'s board (R-08).');

        return $standing['actor'];
    }

    /** @param \Illuminate\Support\Collection<int, Election> $elections */
    private function queueRow(Candidacy $candidacy, $elections): array
    {
        $election = $elections->firstWhere('id', $candidacy->election_id);

        $match = RaceFootprint::bestRaceForUser(
            (string) $candidacy->user_id,
            (string) $candidacy->election_id,
        );

        $slug = null;

        if ($match !== null) {
            $slug = DB::table('jurisdictions')->where('id', $match->jurisdiction_id)->value('slug');
        }

        // "duplicate registration flag" (mockup): the same person holds
        // more than one standing candidacy across open elections.
        $duplicate = Candidacy::query()
            ->where('user_id', (string) $candidacy->user_id)
            ->standing()
            ->count() > 1;

        return [
            'candidacy_id' => (string) $candidacy->id,
            'name'         => $candidacy->user?->display_name ?: ($candidacy->user?->name ?? 'Unknown'),
            'office'       => ($election?->jurisdiction?->name ?? 'Unknown') . ' legislature',
            'residency'    => [
                'found'     => $match !== null,
                'slug'      => $slug,
                'duplicate' => $duplicate,
            ],
        ];
    }

    private function districtOversight(ElectionBoard $board): array
    {
        $maps = DB::table('legislature_district_maps as m')
            ->join('legislatures as l', 'l.id', '=', 'm.legislature_id')
            ->where('l.jurisdiction_id', (string) $board->jurisdiction_id)
            ->whereNull('m.deleted_at')
            ->orderBy('m.created_at')
            ->get(['m.id', 'm.name', 'm.status']);

        return $maps->map(function ($map) {
            $seats = DB::table('legislature_districts')
                ->where('map_id', $map->id)
                ->whereNull('deleted_at')
                ->orderBy('district_number')
                ->pluck('seats');

            $seatString = $seats->count() <= 12
                ? $seats->implode('+')
                : $seats->take(12)->implode('+') . '+…';

            return [
                'map_id'         => (string) $map->id,
                'name'           => $map->name,
                'district_count' => $seats->count(),
                'seat_string'    => $seatString,
                'status'         => $map->status,
            ];
        })->all();
    }

    private function certifiableRow(Election $election): array
    {
        $races = $election->races;

        $rounds = 0;
        $complete = true;

        foreach ($races as $race) {
            // The certified record line: initial or audit_rerun — never a
            // countback (that record belongs to its vacancy page).
            $tabulation = Tabulation::query()
                ->where('race_id', (string) $race->id)
                ->whereIn('kind', [Tabulation::KIND_INITIAL, Tabulation::KIND_AUDIT_RERUN])
                ->complete()
                ->whereNotNull('record_hash')
                ->orderByDesc('completed_at')
                ->first(['id']);

            if ($tabulation === null) {
                $complete = false;

                continue;
            }

            $rounds += (int) DB::table('tabulation_rounds')
                ->where('tabulation_id', (string) $tabulation->id)
                ->max('round_no');
        }

        $certified = ElectionCertification::query()
            ->where('election_id', (string) $election->id)
            ->where('status', ElectionCertification::STATUS_CERTIFIED)
            ->exists();

        $recountOrdered = ElectionAudit::query()
            ->where('election_id', (string) $election->id)
            ->whereNull('outcome')
            ->exists();

        return [
            'election_id'         => (string) $election->id,
            'label'               => $this->electionLabel($election),
            'rounds'              => $rounds,
            'seats'               => (int) $races->sum('seats'),
            'tabulation_complete' => $complete,
            'certified'           => $certified,
            'recount'             => ['ordered' => $recountOrdered],
        ];
    }

    private function electionLabel(Election $election): string
    {
        $seats = (int) $election->races->sum('seats');
        $name = $election->jurisdiction?->name ?? 'Unknown jurisdiction';

        return "{$name} {$election->kind} — {$seats} " . ($seats === 1 ? 'seat' : 'seats')
            . " ({$election->status})";
    }

    private function vacancyLabel(Vacancy $vacancy): string
    {
        $member = $vacancy->seat_type === 'legislature_members'
            ? LegislatureMember::query()->with('user:id,name,display_name')->find($vacancy->seat_id)
            : null;

        $who = $member?->user?->display_name ?: $member?->user?->name;
        $seat = $member?->seat_no !== null ? "seat {$member->seat_no}" : 'seat';

        $name = $vacancy->jurisdiction?->name ?? 'Unknown jurisdiction';

        return "{$name} legislature · {$seat}" . ($who !== null ? " — {$who}" : '');
    }

    private function raceLabel(ElectionRace $race): string
    {
        $jurisdiction = $race->jurisdiction?->name ?? 'Race';

        if ($race->isAtLarge()) {
            return "{$jurisdiction} at-large — {$race->seats} seats";
        }

        $number = $race->district?->district_number;

        return $number !== null
            ? "{$jurisdiction} — district {$number} · {$race->seats} seats"
            : "{$jurisdiction} — {$race->seats} seats";
    }
}
