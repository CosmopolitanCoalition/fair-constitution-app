<?php

namespace App\Http\Controllers\Executive;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\Appointment;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\Department;
use App\Models\DepartmentReport;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\GovernorRemovalRequest;
use App\Models\Term;
use App\Models\User;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-D3 — Departments + DepartmentDetail (PHASE_D_DESIGN_frontend.md
 * §B.2/§B.3; surfaces executive/departments + executive/department-detail).
 *
 *   GET  /executives/{executive}/departments  — the department registry,
 *        the BoG pipeline (F-EXE-001 → F-LEG-020 → R-18), civil officers.
 *   GET  /departments/{department}             — the BoG-consent EXIT
 *        surface: charter & oversight, the two-clock board roster, the
 *        nomination pipeline with the chamber consent VoteTally rendered
 *        on the executive surface, and the MAJORITY (not supermajority)
 *        removal vote.
 *   POST /departments/{department}/nominations      — F-EXE-001 (opens the
 *        F-LEG-020 consent vote via the BoardGovernorService consent lane).
 *   POST /departments/{department}/removal-requests — F-EXE-003 (ordinary
 *        MAJORITY chamber vote — owner ruling #14, deliberately not the
 *        supermajority officeholder-remove machinery).
 *
 * Public read (departments, boards, consent votes are public record —
 * Art. II §2 · Art. III §4); actions gate on the engine (R-14/15/16) and
 * consent casting happens in the legislature (the Phase C vote endpoint).
 *
 * CONSTITUTIONAL POSTURE — pure reader: every threshold / seat-count /
 * composition_valid number is an ENGINE SNAPSHOT off the chamber_votes /
 * boards / board_seats rows; nothing here recomputes the co-determination
 * scale or any vote threshold (ChamberVotePresenter renders the snapshots).
 */
class DepartmentController extends Controller
{
    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly ChamberVotePresenter $votes,
    ) {}

    // =========================================================================
    // GET /executives/{executive}/departments
    // =========================================================================

    public function index(Request $request, Executive $executive): Response
    {
        $executive->loadMissing('jurisdiction');

        $departments = Department::query()
            ->where('executive_id', $executive->id)
            ->where('status', '!=', Department::STATUS_DISSOLVED)
            ->with('board')
            ->orderBy('name')
            ->get();

        $boardSeats = $this->seatsByBoard($departments);

        return Inertia::render('Executive/Departments', [
            'surface' => SurfaceMeta::for('executive/departments'),
            'executive' => $this->executiveHeader($executive),
            'machine' => config('cga.state_machines.department_board'),
            'departments' => $departments
                ->map(fn (Department $d) => $this->departmentCard($d, $boardSeats))
                ->values()
                ->all(),
            'pipeline' => $this->pipelineRows($departments, $boardSeats),
            'civilOfficers' => $this->civilOfficers($executive),
            'createDeepLink' => '/legislature/bills?intro=1&act=department_creation&executive='.$executive->id,
            'can' => [
                'nominate' => $this->isSeatedMember($executive, $request->user()),
            ],
        ]);
    }

    // =========================================================================
    // GET /departments/{department}
    // =========================================================================

    public function show(Request $request, Department $department): Response
    {
        $department->loadMissing(['executive', 'charterLaw', 'board']);

        $board = $department->board;
        $seats = $board !== null ? $this->boardSeatRows($board) : [];

        $appointments = $this->nominationRows($department, $board);
        $removals = $this->removalRows($department, $board);

        $viewerIsMember = $this->isSeatedMember($department->executive, $request->user());

        return Inertia::render('Executive/DepartmentDetail', [
            'surface' => SurfaceMeta::for('executive/department-detail'),
            'department' => [
                'id' => (string) $department->id,
                'name' => $department->name,
                'kind' => $department->kind,
                'status' => $this->displayStatus($department, $board, $seats),
                'worker_count' => (int) ($department->worker_count ?? 0),
                'charter' => [
                    'text_summary' => $this->charterText($department),
                    'act' => $department->charterLaw !== null ? [
                        'act_number' => $department->charterLaw->act_number,
                        'href' => $department->charterLaw->enacting_bill_id !== null
                            ? "/bills/{$department->charterLaw->enacting_bill_id}"
                            : '/system/public-records',
                    ] : null,
                    'reporting_interval_months' => $department->reporting_interval_months,
                ],
                'executive' => [
                    'name' => $this->executiveLabel($department->executive),
                    'href' => '/executives/'.$department->executive_id,
                ],
                'oversees_cgcs' => $this->overseesCgcs($department),
            ],
            'machine' => config('cga.state_machines.department_board'),
            'board' => $board !== null ? [
                'compositionValid' => (bool) $board->composition_valid,
                'requiredWorkerSeats' => (int) $board->worker_seats,
                'owner_seats' => (int) $board->owner_seats,
                'worker_seats' => (int) $board->worker_seats,
                'seats' => $seats,
                'chair' => $this->chairOf($seats),
            ] : null,
            'nominations' => $appointments,
            'removals' => $removals,
            'reporting' => $this->reportingSummary($department),
            'can' => [
                'nominate' => $viewerIsMember && $this->hasVacantGovernorSeat($board),
                'requestRemoval' => $viewerIsMember,
            ],
        ]);
    }

    // =========================================================================
    // POST endpoints — every mutation rides the engine; the engine is the
    // boundary, the route is just the door.
    // =========================================================================

    /** F-EXE-001 — nomination dossier (opens the F-LEG-020 consent vote). */
    public function nominate(Request $request, Department $department): RedirectResponse
    {
        $this->engine->file('F-EXE-001', $request->user(), [
            'department_id' => (string) $department->id,
            'jurisdiction_id' => (string) $department->jurisdiction_id,
            'nominee_user_id' => (string) $request->input('nominee_user_id', ''),
            'dossier' => $request->input('dossier') ?: null,
        ]);

        return back()->with(
            'status',
            'Governor nominated (F-EXE-001) — the dossier is published and the F-LEG-020 consent vote '
            .'is open in the legislature (majority of all serving).'
        );
    }

    /** F-EXE-003 — removal request (ordinary MAJORITY chamber vote). */
    public function requestRemoval(Request $request, Department $department): RedirectResponse
    {
        $this->engine->file('F-EXE-003', $request->user(), [
            'board_seat_id' => (string) $request->input('board_seat_id', ''),
            'jurisdiction_id' => (string) $department->jurisdiction_id,
            'grounds' => (string) $request->input('grounds', ''),
        ]);

        return back()->with(
            'status',
            'Removal requested (F-EXE-003) — grounds published; the legislature decides by ordinary '
            .'majority of all serving (hiring and firing — never the supermajority machinery).'
        );
    }

    // =========================================================================
    // Props assembly
    // =========================================================================

    /** The B.1 executive header (rendered self-contained from the row). */
    private function executiveHeader(Executive $executive): array
    {
        $ends = $executive->term_ends_on;
        $today = now('UTC')->startOfDay();

        return [
            'id' => (string) $executive->id,
            'type' => $executive->type,
            'status' => $executive->status,
            'jurisdiction' => [
                'id' => (string) $executive->jurisdiction_id,
                'name' => $executive->jurisdiction?->name ?? 'Jurisdiction',
                'href' => $executive->jurisdiction?->slug !== null
                    ? "/jurisdictions/{$executive->jurisdiction->slug}"
                    : null,
            ],
            'term' => [
                'starts_on' => $executive->term_starts_on?->toDateString(),
                'ends_on' => $ends?->toDateString(),
                'days_remaining' => $ends !== null ? max(0, (int) $today->diffInDays($ends, false)) : null,
            ],
        ];
    }

    private function executiveLabel(?Executive $executive): string
    {
        if ($executive === null) {
            return 'Executive';
        }

        $name = DB::table('jurisdictions')->where('id', $executive->jurisdiction_id)->value('name');

        return ($name ?? 'Jurisdiction').' executive';
    }

    /**
     * Board seats keyed by board id (one query for the whole registry).
     *
     * @return array<string, \Illuminate\Support\Collection>
     */
    private function seatsByBoard($departments): array
    {
        $boardIds = $departments->pluck('board_id')->filter()->map(fn ($id) => (string) $id)->all();

        if ($boardIds === []) {
            return [];
        }

        return BoardSeat::query()
            ->whereIn('board_id', $boardIds)
            ->whereNotIn('status', [BoardSeat::STATUS_REMOVED, BoardSeat::STATUS_TERM_ENDED])
            ->with('holder:id,name,display_name')
            ->orderBy('seat_no')
            ->get()
            ->groupBy(fn (BoardSeat $s) => (string) $s->board_id)
            ->all();
    }

    /** DepartmentCard props (§A.5) — board figures are engine snapshots. */
    private function departmentCard(Department $department, array $boardSeats): array
    {
        $board = $department->board;
        $seats = $board !== null ? ($boardSeats[(string) $board->id] ?? collect()) : collect();

        return [
            'id' => (string) $department->id,
            'name' => $department->name,
            'kind' => $department->kind,
            'status' => $department->status,
            'worker_count' => (int) ($department->worker_count ?? 0),
            'board' => $board !== null ? [
                'owner_seats' => (int) $board->owner_seats,
                'worker_seats' => (int) $board->worker_seats,
                'composition_valid' => (bool) $board->composition_valid,
                'seats' => $seats->map(fn (BoardSeat $s) => $this->compactSeat($s))->values()->all(),
            ] : null,
            'charter' => $department->charter_law_id !== null ? [
                'act_number' => DB::table('laws')->where('id', $department->charter_law_id)->value('act_number'),
                'href' => '/departments/'.$department->id,
                'reporting_interval_months' => $department->reporting_interval_months,
            ] : null,
            'oversees_cgcs' => $this->overseesCgcs($department),
            'next_report' => $this->nextReport($department),
            'href' => '/departments/'.$department->id,
        ];
    }

    /** Compact seat (pip strip — BoardStrip compact / DepartmentCard). */
    private function compactSeat(BoardSeat $seat): array
    {
        return [
            'id' => (string) $seat->id,
            'seat_class' => $seat->seat_class,
            'holder' => $seat->holder !== null
                ? ['name' => $seat->holder->display_name ?: $seat->holder->name]
                : null,
            'is_chair' => (bool) $seat->is_chair,
            'status' => $seat->status,
        ];
    }

    /** Full board roster row — the two-clock dates rendered verbatim. */
    private function boardSeatRows(Board $board): array
    {
        $today = now('UTC')->startOfDay();

        return BoardSeat::query()
            ->where('board_id', $board->id)
            ->whereNotIn('status', [BoardSeat::STATUS_REMOVED, BoardSeat::STATUS_TERM_ENDED])
            ->with(['holder:id,name,display_name', 'term'])
            ->orderBy('seat_no')
            ->get()
            ->map(function (BoardSeat $seat) use ($today) {
                $term = $seat->term;

                // Worker seats run on CLK-10 (legislative-term lockstep);
                // governor seats on CLK-09 (10-yr civil appointment).
                $clock = $seat->seat_class === BoardSeat::CLASS_WORKER_ELECTED ? 'CLK-10' : 'CLK-09';

                $endsOn = $term?->ends_on;
                $expiring = $seat->status === BoardSeat::STATUS_SEATED
                    && $endsOn !== null
                    && $today->diffInDays($endsOn, false) <= 90
                    && $today->diffInDays($endsOn, false) >= 0;

                return [
                    'id' => (string) $seat->id,
                    'seat_class' => $seat->seat_class,
                    'holder' => $seat->holder !== null
                        ? ['name' => $seat->holder->display_name ?: $seat->holder->name]
                        : null,
                    'is_chair' => (bool) $seat->is_chair,
                    'status' => $seat->status,
                    'expiring' => $expiring,
                    'term' => $term !== null ? [
                        'starts_on' => $term->starts_on?->toDateString(),
                        'ends_on' => $endsOn?->toDateString(),
                        'clock' => $clock,
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    private function chairOf(array $seats): ?array
    {
        foreach ($seats as $seat) {
            if (($seat['is_chair'] ?? false) && ($seat['holder'] ?? null) !== null) {
                return ['name' => $seat['holder']['name']];
            }
        }

        return null;
    }

    /**
     * The BoG pipeline (§B.2): one row per appointment that is not yet
     * resolved-and-seated-and-stale — the live F-EXE-001 → F-LEG-020 →
     * R-18 chain. Consent figures are read off the chamber_votes snapshot.
     *
     * @return list<array<string, mixed>>
     */
    private function pipelineRows($departments, array $boardSeats): array
    {
        $boardIds = $departments->pluck('board_id')->filter()->map(fn ($id) => (string) $id)->all();

        if ($boardIds === []) {
            return [];
        }

        $seatIds = collect($boardSeats)->flatten(1)
            ->filter(fn ($s) => in_array((string) $s->board_id, $boardIds, true))
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        // Map seat → department for labelling.
        $deptByBoard = $departments->keyBy(fn (Department $d) => (string) $d->board_id);

        $appointments = Appointment::query()
            ->where('appointable_type', 'board_seats')
            ->whereIn('appointable_id', $seatIds)
            ->whereIn('status', [Appointment::STATUS_NOMINATED, Appointment::STATUS_CONSENTED])
            ->with('nominee:id,name,display_name')
            ->orderByDesc('created_at')
            ->get();

        return $appointments->map(function (Appointment $appointment) use ($boardSeats, $deptByBoard) {
            $seat = collect($boardSeats)->flatten(1)->firstWhere('id', $appointment->appointable_id);
            $board = $seat !== null ? (string) $seat->board_id : null;
            $dept = $board !== null ? $deptByBoard->get($board) : null;

            $vote = $appointment->consent_vote_id !== null
                ? ChamberVote::query()->with('tallies')->find($appointment->consent_vote_id)
                : null;

            return [
                'department' => $dept !== null
                    ? ['name' => $dept->name, 'href' => '/departments/'.$dept->id]
                    : ['name' => 'Department', 'href' => '#'],
                'nominee' => ['name' => $this->userName($appointment->nominee)],
                'dossier_at' => $appointment->created_at?->toIso8601String(),
                'consent' => $vote !== null ? [
                    'vote' => $this->votes->tallyProps($vote),
                    'scheduled' => $vote->status === ChamberVote::STATUS_OPEN,
                    'outcome' => $vote->status === ChamberVote::STATUS_CLOSED ? $vote->outcome : null,
                    // The legislature is where R-09s cast (the Phase C
                    // /votes/{vote}/cast endpoint); link to its chamber.
                    'chamber_href' => $vote->legislature_id !== null
                        ? '/legislatures/'.$vote->legislature_id.'/chamber'
                        : null,
                ] : null,
                'seated' => $appointment->status === Appointment::STATUS_SEATED
                    ? ['term' => null]
                    : null,
                'stepper' => $this->stepperFor($appointment, $vote),
            ];
        })->values()->all();
    }

    /** R-30 thin slice — department civil officers on CLK-09 terms. */
    private function civilOfficers(Executive $executive): array
    {
        $deptIds = Department::query()
            ->where('executive_id', $executive->id)
            ->where('status', '!=', Department::STATUS_DISSOLVED)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($deptIds === []) {
            return [];
        }

        $deptNames = Department::query()->whereIn('id', $deptIds)->pluck('name', 'id');

        return Term::query()
            ->where('office_kind', 'civil_officer')
            ->where('status', Term::STATUS_ACTIVE)
            ->whereIn('office_id', function ($sub) use ($deptIds) {
                $sub->select('id')->from('appointments')
                    ->where('appointable_type', 'departments')
                    ->whereIn('appointable_id', $deptIds);
            })
            ->with('holder:id,name,display_name')
            ->get()
            ->map(function (Term $term) use ($deptNames) {
                $deptId = DB::table('appointments')->where('id', $term->office_id)->value('appointable_id');

                return [
                    'name' => $this->userName($term->holder),
                    'department' => $deptId !== null ? ($deptNames[(string) $deptId] ?? 'Department') : 'Department',
                    'role_label' => 'Civil officer',
                    'term' => ['ends_on' => $term->ends_on?->toDateString()],
                    'clock' => 'CLK-09',
                ];
            })
            ->values()
            ->all();
    }

    /** Per-nomination cards on DepartmentDetail (§B.3). */
    private function nominationRows(Department $department, ?Board $board): array
    {
        if ($board === null) {
            return [];
        }

        $seatIds = BoardSeat::query()->where('board_id', $board->id)->pluck('id')->map(fn ($id) => (string) $id)->all();

        if ($seatIds === []) {
            return [];
        }

        return Appointment::query()
            ->where('appointable_type', 'board_seats')
            ->whereIn('appointable_id', $seatIds)
            ->with(['nominee:id,name,display_name', 'term'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Appointment $appointment) {
                $vote = $appointment->consent_vote_id !== null
                    ? ChamberVote::query()->with('tallies')->find($appointment->consent_vote_id)
                    : null;

                $seat = BoardSeat::query()->find($appointment->appointable_id);
                $term = $appointment->term;

                return [
                    'id' => (string) $appointment->id,
                    'nominee' => ['name' => $this->userName($appointment->nominee)],
                    'status' => $this->nominationStatus($appointment, $seat),
                    'consent_vote' => $vote !== null ? [
                        'tally' => $this->votes->tallyProps($vote),
                        'casts' => $this->votes->casts($vote),
                    ] : null,
                    'term' => $term !== null ? [
                        'starts_on' => $term->starts_on?->toDateString(),
                        'ends_on' => $term->ends_on?->toDateString(),
                    ] : null,
                    'stepper' => $this->stepperFor($appointment, $vote),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Live removal cards (§B.3) — the MAJORITY VoteTally, deliberately NOT
     * supermajority (owner ruling #14; the presenter reads the snapshot).
     */
    private function removalRows(Department $department, ?Board $board): array
    {
        if ($board === null) {
            return [];
        }

        $seatIds = BoardSeat::query()->where('board_id', $board->id)->pluck('id')->map(fn ($id) => (string) $id)->all();

        if ($seatIds === []) {
            return [];
        }

        return GovernorRemovalRequest::query()
            ->whereIn('board_seat_id', $seatIds)
            ->with('seat.holder:id,name,display_name')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (GovernorRemovalRequest $request) {
                $vote = $request->vote_id !== null
                    ? ChamberVote::query()->with('tallies')->find($request->vote_id)
                    : null;

                return [
                    'id' => (string) $request->id,
                    'subject' => ['name' => $this->userName($request->seat?->holder)],
                    'grounds_published' => $request->grounds,
                    'vote' => $vote !== null ? [
                        'tally' => $this->votes->tallyProps($vote),
                        'casts' => $this->votes->casts($vote),
                    ] : null,
                    'outcome' => $request->outcome,
                ];
            })
            ->values()
            ->all();
    }

    /** Reporting summary card → DepartmentReporting link. */
    private function reportingSummary(Department $department): array
    {
        $lastFiled = DepartmentReport::query()
            ->where('department_id', $department->id)
            ->where('status', DepartmentReport::STATUS_FILED)
            ->orderByDesc('filed_at')
            ->first();

        $next = $this->nextReport($department);

        return [
            'last_filed' => $lastFiled !== null ? [
                'kind' => $lastFiled->kind,
                'at' => $lastFiled->filed_at?->toIso8601String(),
                'record_href' => $lastFiled->record_id !== null ? '/system/public-records' : null,
            ] : null,
            'next_due' => $next,
            'reporting_href' => "/departments/{$department->id}/reporting",
        ];
    }

    private function nextReport(Department $department): ?array
    {
        $report = DepartmentReport::query()
            ->where('department_id', $department->id)
            ->whereIn('status', [DepartmentReport::STATUS_DUE, DepartmentReport::STATUS_OVERDUE])
            ->orderBy('due_on')
            ->first();

        if ($report === null) {
            return null;
        }

        $status = $report->status;
        if ($status === DepartmentReport::STATUS_DUE
            && $report->due_on !== null
            && now('UTC')->startOfDay()->diffInDays($report->due_on, false) <= 14
            && now('UTC')->startOfDay()->diffInDays($report->due_on, false) >= 0) {
            $status = 'due_soon';
        }

        return [
            'due_on' => $report->due_on?->toDateString(),
            'on' => $report->due_on?->toDateString(),
            'status' => $status,
        ];
    }

    /**
     * Stepper steps for an appointment (§A.7 — the BoG pipeline).
     *
     * @return list<array{label: string, icon: string, state: string}>
     */
    private function stepperFor(Appointment $appointment, ?ChamberVote $vote): array
    {
        $seated = $appointment->status === Appointment::STATUS_SEATED;
        $consented = $seated
            || $appointment->status === Appointment::STATUS_CONSENTED
            || ($vote !== null && $vote->status === ChamberVote::STATUS_CLOSED && $vote->outcome === ChamberVote::OUTCOME_ADOPTED);

        return [
            ['label' => 'Nomination dossier · F-EXE-001', 'icon' => 'file-text', 'state' => 'done'],
            [
                'label' => 'Consent vote · F-LEG-020',
                'icon' => 'landmark',
                'state' => $consented ? 'done' : ($vote !== null && $vote->status === ChamberVote::STATUS_OPEN ? 'active' : 'pending'),
            ],
            [
                'label' => 'Seated · R-18',
                'icon' => 'check',
                'state' => $seated ? 'done' : ($consented ? 'active' : 'pending'),
            ],
        ];
    }

    private function nominationStatus(Appointment $appointment, ?BoardSeat $seat): string
    {
        if ($seat !== null && $seat->status === BoardSeat::STATUS_SEATED
            && (string) $seat->appointment_id === (string) $appointment->id) {
            return 'seated';
        }

        return match ($appointment->status) {
            Appointment::STATUS_SEATED => 'seated',
            Appointment::STATUS_CONSENTED => 'consented',
            Appointment::STATUS_REJECTED => 'rejected',
            Appointment::STATUS_ENDED => 'ended',
            default => 'nominated',
        };
    }

    /**
     * ESM-17 display status: an open governor-removal request splices the
     * synthetic `removal_requested` state into the machine without
     * disturbing the stored status (the CandidacyController::machineFor()
     * idiom called out in the state-machine config).
     */
    private function displayStatus(Department $department, ?Board $board, array $seats): string
    {
        foreach ($seats as $seat) {
            if (($seat['status'] ?? null) === BoardSeat::STATUS_REMOVAL_REQUESTED) {
                return 'removal_requested';
            }
        }

        return $department->status;
    }

    /** Charter text = the current law version's text (laws have no text column). */
    private function charterText(Department $department): ?string
    {
        if ($department->charter_law_id === null) {
            return null;
        }

        return DB::table('law_versions')
            ->where('law_id', $department->charter_law_id)
            ->orderByDesc('version_no')
            ->value('text');
    }

    /**
     * CGCs overseen by THIS department. The backend models CGC oversight at
     * the executive level (organizations.overseen_by_executive_id), not as a
     * per-department FK — there is no department↔CGC column to source from.
     * The honest empty state renders until that link exists (noted for the
     * consolidator; see DepartmentDetail's oversight card gloss).
     */
    private function overseesCgcs(Department $department): array
    {
        return [];
    }

    private function hasVacantGovernorSeat(?Board $board): bool
    {
        if ($board === null) {
            return false;
        }

        return BoardSeat::query()
            ->where('board_id', $board->id)
            ->where('seat_class', BoardSeat::CLASS_GOVERNOR)
            ->where('status', BoardSeat::STATUS_VACANT)
            ->exists();
    }

    private function isSeatedMember(?Executive $executive, ?User $user): bool
    {
        if ($executive === null || $user === null) {
            return false;
        }

        return ExecutiveMember::query()
            ->where('executive_id', $executive->id)
            ->where('user_id', (string) $user->getKey())
            ->where('status', ExecutiveMember::STATUS_SEATED)
            ->exists();
    }

    private function userName(?User $user): string
    {
        return $user?->display_name ?: ($user?->name ?? 'Unnamed');
    }
}
