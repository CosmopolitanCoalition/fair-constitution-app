<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Models\SimRun;
use App\Services\Demo\SimRunControl;
use App\Services\Demo\SimSnapshot;
use App\Support\HostCapacity;
use App\Support\InstanceClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The simulated-world populate console — the surface an operator leaves OPEN
 * while a generation run happens, the way the district mapper's Step-3 page is
 * left open while lane 1 works.
 *
 * THE PATTERN IS DELIBERATE AND BORROWED WHOLE. There is no progress table:
 * every poll runs a fresh `COUNT(*) FILTER (…) GROUP BY kind` against
 * `sim_items_claim_idx`, exactly as `SetupController::autoscaleProgress()` does
 * — "real numbers every 2 s, never the pump's once-a-minute denormalized
 * copies". The run row's counters exist for the pump's own bookkeeping and are
 * deliberately NOT what the bars read, because a counter refreshed once a
 * minute makes a working engine look frozen (the operator's own finding on
 * autoscale run 2).
 *
 * The worker strip reads `sim_worker_leases`, which was made byte-compatible
 * with `autoscale_worker_leases` for precisely this reason: the substrate the
 * live view needs already existed the moment the engine did.
 */
class SimConsoleController extends Controller
{
    public function __construct(
        private readonly SimRunControl $control,
        private readonly SimSnapshot $snap,
    ) {}

    /** The page shell. Data arrives from the poll below. */
    public function show(): Response
    {
        // The console is public-read (Art. II §2 — a citizen may watch the
        // machinery). The DRIVE controls are not: only a signed-in operator sees
        // them, and only a synthetic-safe world may run them. A non-operator sees
        // the same live bars with no buttons; an operator on a real world sees the
        // refusal SENTENCE verbatim (the D2 contract) where the buttons would be.
        return Inertia::render('Demo/SimConsole', [
            'instanceClass' => InstanceClass::current(),
            'isScaleDemo' => InstanceClass::isScaleDemo(),
            'canControl' => Auth::guard('operator')->check(),
            'controlRefusal' => $this->control->refusalReason(),
            'initial' => $this->snapshot(),
        ]);
    }

    /** Polled every 2 s by the page. Cheap, index-only, never cached. */
    public function progress(): JsonResponse
    {
        return response()->json($this->snapshot());
    }

    /**
     * START a populate run (operator, synthetic-safe worlds only — enforced in
     * the service). Async: the service queues the real `sim:start` command, so
     * the ~907k-row enumeration never runs inside this request. The refusal
     * sentence, when there is one, is returned verbatim for the console to show.
     */
    public function start(Request $request): JsonResponse
    {
        $options = [
            'world-version' => $request->integer('world_version', 1),
            'turnout' => $request->integer('turnout', 62),
            'adm-max' => $request->integer('adm_max', 6),
            'limit' => $request->input('limit'),
            'resume' => $request->boolean('resume'),
        ];

        return response()->json($this->control->start($options, $this->actor()));
    }

    /** HALT the active run — instant flag write; the pump parks workers. */
    public function halt(): JsonResponse
    {
        return response()->json($this->control->halt($this->actor()));
    }

    /** RESUME a halted run — clear the flag; the pump flips it back to running. */
    public function resume(): JsonResponse
    {
        return response()->json($this->control->resume($this->actor()));
    }

    /** The operator username on the audit mark, or a stable label if none resolves. */
    private function actor(): string
    {
        return Auth::guard('operator')->user()?->username ?? 'operator';
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        // The progress poll is public-to-authenticated (Art. II §2 — a citizen may
        // WATCH), but the control marker carries the operator's username and the
        // raw sim:start output. That is neither a count nor a place name, so it is
        // operator-only: a non-operator sees the same live bars with control null.
        $control = Auth::guard('operator')->check() ? $this->control->control() : null;

        // SimSnapshot is the single owner of the sim's live reads (ruling 10) —
        // the same numbers the /setup/step/5 page shows, so the two surfaces
        // cannot drift. The console adds its own honesty rails (over_bound,
        // seat_gap) and its live-items list on top; those stay here.
        $run = $this->snap->activeOrLatestRun();

        if ($run === null) {
            return [
                'run' => null,
                'stages' => [],
                'workers' => [],
                'live_items' => [],
                'review_items' => [],
                'world' => $this->world(),
                'control' => $control,
            ];
        }

        return [
            'control' => $control,
            'run' => [
                'id' => (string) $run->id,
                'status' => $run->status,
                'phase' => $run->phase,
                'phases' => SimRun::PHASES,
                'halt_requested' => $run->haltRequested(),
                'paused_until' => $run->paused_until?->toIso8601String(),
                'is_paused' => $run->isPaused(),
                'last_error' => $run->last_error,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'options' => $run->options,
                'workers_target' => HostCapacity::autoscaleWorkers(),
                'phase_timings' => $run->phase_timings,
            ],
            'stages' => $this->snap->stages($run),
            'workers' => $this->snap->lanes($run),
            'live_items' => $this->liveItems($run),
            'review_items' => $this->snap->reviewItems($run),
            'world' => $this->world(),
        ];
    }

    /** What is being worked on RIGHT NOW, by name. */
    private function liveItems(SimRun $run): array
    {
        return DB::table('sim_items as s')
            ->leftJoin('jurisdictions as j', 'j.id', '=', 's.jurisdiction_id')
            ->where('s.run_id', $run->id)
            ->where('s.status', 'running')
            ->orderBy('s.started_at')
            ->limit(15)
            ->get(['s.kind', 's.started_at', 'j.name as jurisdiction'])
            ->map(fn ($i) => [
                'kind' => $i->kind,
                'jurisdiction' => $i->jurisdiction ?? '—',
                'started_at' => $i->started_at,
            ])
            ->all();
    }

    /**
     * What the run has PRODUCED — the shared counts from SimSnapshot (single
     * owner, cached) PLUS the console's own honesty rails: the active-map count
     * and the over-bound / seat-gap lists that render by name on this surface.
     *
     * BUILT IS NOT GOVERNED. The shared counts already separate chambers from
     * chambers_governed; these rails add the two ways a built world can still be
     * wrong — a Type B half over its bound, and drawn seats that cannot be
     * filled — so the console never lets a defect render as completeness.
     */
    private function world(): array
    {
        return $this->snap->world() + [
            // A DRAFT map is a proposal, not a boundary — count active() only.
            'active_district_maps' => (int) DB::table('legislature_district_maps')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->count(),
            'over_bound' => $this->overBoundChambers(),
            'seat_gap' => $this->unfillableSeats(),
        ];
    }

    /**
     * Chambers whose drawn districts do not sum to their Type A total.
     *
     * DRIFT IS ALWAYS WRONG (operator ruling 2026-07-26). The chamber size is
     * FIXED by the cube-root law, so a district plan that misses it leaves seats
     * either unfillable or unallotted — there is no race for them and no lawful
     * way to seat them. It is never "noise" and never "close enough".
     *
     * San Marino's national chamber is the live instance: population 33,312 →
     * Type A 32 by the law, but its active map draws 8 + 7 + 7 + 9 = 31. The
     * election filled every seat it had a race for and still landed 58/59, and
     * it will land 58/59 at every future election, because the missing seat has
     * no district. Nothing downstream can repair that — only redrawing the map
     * can, which is why this reports the gap rather than trying to absorb it.
     *
     * Type B is excluded on purpose: an at-large chamber IS its own district, so
     * it cannot drift. This measures the drawn plan only.
     *
     * @return array<string,mixed>
     */
    private function unfillableSeats(): array
    {
        $rows = DB::table('legislatures as l')
            ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
            ->whereNull('l.deleted_at')
            ->whereNull('j.deleted_at')
            ->where('l.type_a_seats', '>', 9) // below the ceiling a chamber is at-large — no plan to miss
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('legislature_district_maps as m')
                    ->whereColumn('m.legislature_id', 'l.id')
                    ->where('m.status', 'active')
                    ->whereNull('m.deleted_at');
            })
            ->selectRaw("
                j.name,
                l.type_a_seats,
                COALESCE((
                    SELECT SUM(d.seats) FROM legislature_districts d
                      JOIN legislature_district_maps m ON m.id = d.map_id
                     WHERE d.legislature_id = l.id AND m.status = 'active'
                       AND d.deleted_at IS NULL AND m.deleted_at IS NULL
                ), 0) AS drawn
            ")
            ->get()
            ->filter(fn ($r) => (int) $r->drawn !== (int) $r->type_a_seats)
            ->sortByDesc(fn ($r) => abs((int) $r->type_a_seats - (int) $r->drawn))
            ->take(25);

        return [
            'count' => $rows->count(),
            'places' => $rows->map(fn ($r) => [
                'name' => $r->name,
                'type_a' => (int) $r->type_a_seats,
                'drawn' => (int) $r->drawn,
                'gap' => (int) $r->type_a_seats - (int) $r->drawn,
            ])->values()->all(),
        ];
    }

    /**
     * THE HONESTY RAIL — chambers whose Type B half exceeds the Type A total.
     *
     * Art. V §3 as settled 2026-07-26: Type B may not exceed the Type A total.
     * `TypeBSeatLadder` steps 5 → 4 → 3 → 2 and FLOORS there; a chamber still
     * over the bound at 2-per-constituent is flagged and stops, because stage
     * two — grouping constituent jurisdictions into shared panels — is not
     * built yet. Planet-wide that is 9,708 chambers, flagged 1:1.
     *
     * WHY THIS IS ON SCREEN RATHER THAN IN A LOG. Niue's national chamber reads
     * `type_a 11 / type_b 14` and this engine seated all 25 of its seats before
     * the flag reached me. A visitor looking at "25 / 25 filled" sees a complete
     * government; what is actually there is a lower house of 11 and an upper
     * house of 14 that no settled rule authorises. Whether a flagged chamber may
     * lawfully hold an election is the operator's question, not mine — but
     * letting it render as ordinary while he decides would be dressing a known
     * defect up as attained consent, which is the one thing this page exists to
     * refuse. So it renders, by name, seated or not.
     *
     * @return array<string,mixed>
     */
    private function overBoundChambers(): array
    {
        $rows = DB::table('legislatures as l')
            ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
            ->whereNull('l.deleted_at')
            ->whereNull('j.deleted_at')
            ->where('l.type_b_seats', '>', 0)
            ->whereColumn('l.type_b_seats', '>', 'l.type_a_seats')
            ->orderByDesc('l.type_b_seats')
            ->limit(25)
            ->get(['j.name', 'l.id', 'l.type_a_seats', 'l.type_b_seats']);

        $seatedIds = $rows->isEmpty() ? [] : DB::table('legislature_members')
            ->whereIn('legislature_id', $rows->pluck('id'))
            ->whereIn('status', ['elected', 'seated'])
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('legislature_id')
            ->all();

        return [
            'count' => $rows->count(),
            'places' => $rows->map(fn ($r) => [
                'name' => $r->name,
                'type_a' => (int) $r->type_a_seats,
                'type_b' => (int) $r->type_b_seats,
                // A flagged chamber that is EMPTY is merely waiting. A flagged
                // chamber that is SEATED has already produced members under a
                // seat count no rule authorises — a materially worse state, and
                // the page must not level the two.
                'seated' => in_array($r->id, $seatedIds, true),
            ])->values()->all(),
        ];
    }
}
