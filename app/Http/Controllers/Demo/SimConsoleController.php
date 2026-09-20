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
 * Progress bars read the same short-lived, timestamped SimSnapshot sample as
 * Step 5. Concurrent viewers share the aggregate instead of repeating a scan.
 * Controls and the worker strip remain fresh on every poll.
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

    /** Polled every 2 s; progress aggregates are shared across viewers. */
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

    public function repair(Request $request, \App\Services\Demo\SimRepairControl $repair): JsonResponse
    {
        $data = $request->validate(['source' => 'required_without:apply|uuid', 'apply' => 'nullable|uuid',
            'scopes' => 'array|max:100', 'scopes.*' => 'uuid', 'version' => 'integer|min:1']);
        try {
            if (! empty($data['apply'])) {
                $run = SimRun::findOrFail($data['apply']); $repair->apply($run);
            } else {
                $run = $repair->start($data['source'], $data['scopes'] ?? [], $data['version'] ?? 1);
            }
            return response()->json(['ok' => true, 'run_id' => $run->id]);
        } catch (\RuntimeException $error) { return response()->json(['ok' => false, 'reason' => $error->getMessage()], 422); }
    }

    public function repairReport(Request $request, SimRun $run, \App\Services\Demo\SimRepairControl $repair): JsonResponse
    {
        $data = $request->validate(['after' => 'nullable|uuid']);
        return response()->json($repair->report($run, $data['after'] ?? null));
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

        $progress = $this->snap->progress($run);

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
            'stages' => $progress['stages'],
            'progress_snapshot' => array_intersect_key($progress, array_flip(['snapshot_at', 'snapshot_stale', 'snapshot_state'])),
            'workers' => $this->snap->lanes($run),
            'timings' => $this->snap->timings($run),
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
     * owner, cached). The console's honesty rails (active-map count, over-bound
     * and seat-gap lists) are NOT here: they walked every chamber on the box on
     * every 2 s poll (94 s a page on 2026-09-14, W-0443). The page asks
     * rails() for them once after it mounts.
     */
    private function world(): array
    {
        return $this->snap->world();
    }

    /**
     * GET /api/simworld/rails — the honesty rails, lazy and bounded (W-0443).
     *
     * BUILT IS NOT GOVERNED. The shared counts already separate chambers from
     * chambers_governed; these rails add the two ways a built world can still be
     * wrong — a Type B half over its bound, and drawn seats that cannot be
     * filled — so the console never lets a defect render as completeness.
     *
     * Every read here is an index read. The drawn-seat total and the gap sit on
     * the map row (kept by the database, migration 2026_09_14_223000); drifted
     * active maps and over-bound chambers each have a partial index, so the two
     * lists cost their own size, never the planet's. DRIFT IS ALWAYS WRONG
     * (operator ruling 2026-07-26): a plan that misses the cube-root total
     * leaves seats unfillable or unallotted, so it renders by name. Type B is
     * excluded from the gap on purpose: an at-large chamber is its own district.
     * Art. V §3 (settled 2026-07-26): Type B may not exceed the Type A total; a
     * chamber still over the bound at 2-per-constituent renders by name, seated
     * or not, because a seated one has produced members under a seat count no
     * rule authorises.
     */
    public function rails(): JsonResponse
    {
        $activeMaps = (int) DB::table('legislature_district_maps')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->count();

        $gapRows = DB::table('legislature_district_maps as m')
            ->join('legislatures as l', 'l.id', '=', 'm.legislature_id')
            ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
            ->where('m.status', 'active')
            ->whereNull('m.deleted_at')
            ->whereNotNull('m.seat_gap')
            ->where('m.seat_gap', '<>', 0)
            ->where('l.type_a_seats', '>', 9) // below the ceiling a chamber is at-large — no plan to miss
            ->whereNull('l.deleted_at')
            ->orderByRaw('abs(m.seat_gap) DESC')
            ->limit(25)
            ->get(['j.name', 'l.type_a_seats', 'm.drawn_seats', 'm.seat_gap']);

        $gapCount = (int) DB::table('legislature_district_maps as m')
            ->join('legislatures as l', 'l.id', '=', 'm.legislature_id')
            ->where('m.status', 'active')
            ->whereNull('m.deleted_at')
            ->whereNotNull('m.seat_gap')
            ->where('m.seat_gap', '<>', 0)
            ->where('l.type_a_seats', '>', 9)
            ->count();

        $overRows = DB::table('legislatures as l')
            ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
            ->whereNull('l.deleted_at')
            ->whereNull('j.deleted_at')
            ->whereColumn('l.type_b_seats', '>', 'l.type_a_seats')
            ->orderByDesc('l.type_b_seats')
            ->limit(25)
            ->get(['j.name', 'l.id', 'l.type_a_seats', 'l.type_b_seats']);

        $overCount = (int) DB::table('legislatures as l')
            ->whereNull('l.deleted_at')
            ->whereColumn('l.type_b_seats', '>', 'l.type_a_seats')
            ->count();

        $seatedIds = $overRows->isEmpty() ? [] : DB::table('legislature_members')
            ->whereIn('legislature_id', $overRows->pluck('id'))
            ->whereIn('status', ['elected', 'seated'])
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('legislature_id')
            ->all();

        return response()->json([
            'active_district_maps' => $activeMaps,
            'seat_gap' => [
                'count' => $gapCount,
                'places' => $gapRows->map(fn ($r) => [
                    'name' => $r->name,
                    'type_a' => (int) $r->type_a_seats,
                    'drawn' => (int) $r->drawn_seats,
                    'gap' => (int) $r->seat_gap,
                ])->values()->all(),
            ],
            'over_bound' => [
                'count' => $overCount,
                'places' => $overRows->map(fn ($r) => [
                    'name' => $r->name,
                    'type_a' => (int) $r->type_a_seats,
                    'type_b' => (int) $r->type_b_seats,
                    // A flagged chamber that is EMPTY is merely waiting. A flagged
                    // chamber that is SEATED has already produced members under a
                    // seat count no rule authorises — a materially worse state.
                    'seated' => in_array($r->id, $seatedIds, true),
                ])->values()->all(),
            ],
        ]);
    }
}
