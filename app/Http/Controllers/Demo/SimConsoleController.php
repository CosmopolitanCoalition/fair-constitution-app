<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Models\SimRun;
use App\Support\HostCapacity;
use App\Support\InstanceClass;
use Illuminate\Http\JsonResponse;
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
    /** The page shell. Data arrives from the poll below. */
    public function show(): Response
    {
        return Inertia::render('Demo/SimConsole', [
            'instanceClass' => InstanceClass::current(),
            'isScaleDemo' => InstanceClass::isScaleDemo(),
            'initial' => $this->snapshot(),
        ]);
    }

    /** Polled every 2 s by the page. Cheap, index-only, never cached. */
    public function progress(): JsonResponse
    {
        return response()->json($this->snapshot());
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        $run = SimRun::query()
            ->whereIn('status', ['queued', 'running', 'halted'])
            ->orderBy('created_at')
            ->first()
            ?? SimRun::query()->orderByDesc('created_at')->first();

        if ($run === null) {
            return [
                'run' => null,
                'stages' => [],
                'workers' => [],
                'live_items' => [],
                'review_items' => [],
                'world' => $this->world(),
            ];
        }

        return [
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
            'stages' => $this->stages($run),
            'workers' => $this->workers($run),
            'live_items' => $this->liveItems($run),
            'review_items' => $this->reviewItems($run),
            'world' => $this->world(),
        ];
    }

    /**
     * One bar per item kind — the fresh GROUP BY that IS the progress store.
     * Ordered by the phase DAG so the page reads top-to-bottom as the run
     * actually proceeds.
     *
     * @return list<array<string,mixed>>
     */
    private function stages(SimRun $run): array
    {
        $rows = DB::table('sim_items')
            ->where('run_id', $run->id)
            ->selectRaw("
                kind,
                COUNT(*)                                              AS total,
                COUNT(*) FILTER (WHERE status = 'done')               AS done,
                COUNT(*) FILTER (WHERE status = 'running')            AS running,
                COUNT(*) FILTER (WHERE status IN ('review','failed')) AS review
            ")
            ->groupBy('kind')
            ->get()
            ->keyBy('kind');

        $order = [];
        foreach (SimRun::PHASE_KINDS as $phase => $kinds) {
            foreach ($kinds as $kind) {
                $order[$kind] = $phase;
            }
        }

        $stages = [];

        foreach ($order as $kind => $phase) {
            $row = $rows->get($kind);

            if ($row === null) {
                continue; // a stage with no worklist is not yet minted
            }

            $stages[] = [
                'kind' => $kind,
                'phase' => $phase,
                'label' => self::LABELS[$kind] ?? $kind,
                'total' => (int) $row->total,
                'done' => (int) $row->done,
                'running' => (int) $row->running,
                'review' => (int) $row->review,
                'is_current' => $phase === $run->phase,
            ];
        }

        return $stages;
    }

    private const LABELS = [
        'manifest' => 'Enumerating the world',
        'profile_research' => 'Researching localities',
        'profile_inherit' => 'Inheriting profiles',
        'cohort_scope' => 'Deciding who lives where',
        'identity_batch' => 'Minting people',
        'election_scope' => 'Calling elections',
        'count_race' => 'Counting ballots',
        'seat_scope' => 'Seating representatives',
        'acceptance_scan' => 'Checking the world',
    ];

    /**
     * One honest line per live worker — what it is holding and for how long.
     * Two minutes is the liveness horizon, matching the pump's own seeding rule.
     *
     * @return list<array<string,mixed>>
     */
    private function workers(SimRun $run): array
    {
        return DB::table('sim_worker_leases')
            ->where('run_id', $run->id)
            ->where('last_seen_at', '>', now()->subMinutes(2))
            ->orderBy('started_at')
            ->get()
            ->map(fn ($w) => [
                'id' => substr((string) $w->id, 0, 8),
                'claim_type' => $w->claim_type,
                'claim_label' => $w->claim_label,
                'claim_secs' => $w->claim_started_at
                    ? now()->diffInSeconds(\Carbon\Carbon::parse($w->claim_started_at))
                    : null,
            ])
            ->values()
            ->all();
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
     * What REFUSED, and why. A run never dies of a failed item — it settles as
     * review and keeps going — so this list is the honest record of what the
     * world could not build, and it is supposed to be readable.
     */
    private function reviewItems(SimRun $run): array
    {
        return DB::table('sim_items as s')
            ->leftJoin('jurisdictions as j', 'j.id', '=', 's.jurisdiction_id')
            ->where('s.run_id', $run->id)
            ->whereIn('s.status', ['review', 'failed'])
            ->orderBy('s.position')
            ->limit(50)
            ->get(['s.kind', 's.reason', 'j.name as jurisdiction'])
            ->map(fn ($i) => [
                'kind' => $i->kind,
                'jurisdiction' => $i->jurisdiction ?? '—',
                'reason' => $i->reason,
            ])
            ->all();
    }

    /**
     * What the run has actually PRODUCED — the point of the whole engine, and
     * the numbers that make the bars mean something.
     */
    private function world(): array
    {
        return [
            'jurisdictions' => (int) DB::table('jurisdictions')->whereNull('deleted_at')->count(),
            'cohorts' => (int) DB::table('jurisdiction_cohorts')->count(),
            // 'sim-%' not just '@demo.invalid': the reserved namespace is shared
            // with other fixtures (lane 13's founding operator lives there too),
            // and a headline number on screen must count only what THIS engine made.
            'people' => (int) DB::table('users')->where('email', 'like', 'sim-%@demo.invalid')->count(),
            'residencies' => (int) DB::table('residency_confirmations')->where('is_active', true)->count(),
            'population_modelled' => (int) DB::table('jurisdiction_cohorts')->sum('population'),
            'electorate_modelled' => (int) DB::table('jurisdiction_cohorts')->sum('electorate'),
        ];
    }
}
