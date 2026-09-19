<?php

namespace App\Services\Demo;

use App\Models\SimRun;
use App\Services\Setup\SetupProgressRollup;
use App\Support\HostCapacity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * THE ONE READER of the simulated-world run's live state.
 *
 * WHY A SHARED SERVICE (ruling 10 — UI↔CLI parity, single-owner rails). Two
 * surfaces watch the same run: the public /simworld console
 * (SimConsoleController) and the operator's /setup/step/5 page (SetupController).
 * If each computed its own counts they would drift — one would cache the
 * expensive aggregate, the other would not; one would learn a new item kind, the
 * other would show it as a raw slug. So every read lives HERE, once, and both
 * surfaces are thin callers that shape the same numbers to their own chrome.
 *
 * Progress counts share one timestamped, ten-second cache across both pages.
 * A single aggregate supplies totals, stage bars and layers. A cache lock makes
 * concurrent viewers reuse the previous sample while one reader refreshes it.
 * Run/phase/status changes get a distinct key; controls and worker leases stay
 * fresh. world() separately reads run counters and the warmed scope rollup.
 */
class SimSnapshot
{
    /** How long the expensive world() aggregate is reused across pollers. */
    public const WORLD_TTL = 10;

    public const WORLD_KEY = 'sim:world';

    public const PROGRESS_TTL = 10;

    /** Retain a previous sample while another reader refreshes it. */
    private const PROGRESS_RETAIN = 600;

    /**
     * Shared counts only: no viewer identity, permissions or control state.
     * On a cold concurrent miss, return "computing" instead of another scan.
     */
    public function progress(SimRun $run): array
    {
        $key = 'sim:progress:v1:'.$run->id.':'.$run->phase.':'.$run->status;
        $cached = Cache::get($key);
        if (is_array($cached) && now()->timestamp - $cached['computed_ts'] < self::PROGRESS_TTL) {
            return $cached;
        }

        $lock = Cache::lock($key.':refresh', self::PROGRESS_RETAIN);
        if (! $lock->get()) {
            return $cached
                ? array_replace($cached, ['snapshot_stale' => true])
                : $this->emptyProgress();
        }

        try {
            // Another reader may have published between our get and lock.
            $cached = Cache::get($key);
            if (is_array($cached) && now()->timestamp - $cached['computed_ts'] < self::PROGRESS_TTL) {
                return $cached;
            }
            $snapshot = $this->computeProgress($run);
            Cache::put($key, $snapshot, self::PROGRESS_RETAIN);

            return $snapshot;
        } finally {
            $lock->release();
        }
    }

    private function emptyProgress(): array
    {
        return [
            'ledger' => array_fill_keys(['total', 'done', 'running', 'pending', 'review'], 0),
            'stages' => [], 'layers' => [],
            'rate' => ['rate_per_h' => null, 'rate_label' => null],
            'snapshot_at' => null, 'computed_ts' => 0,
            'snapshot_stale' => true, 'snapshot_state' => 'computing',
        ];
    }

    private function computeProgress(SimRun $run): array
    {
        // One run-scoped aggregate, using the existing (run,kind,level,status)
        // index. The ledger includes even kinds not yet known by the UI.
        $rows = DB::table('sim_items')
            ->where('run_id', $run->id)
            ->selectRaw("kind, COALESCE(adm_level, 99) AS adm_level,
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = 'done') AS done,
                COUNT(*) FILTER (WHERE status = 'running') AS running,
                COUNT(*) FILTER (WHERE status = 'pending') AS pending,
                COUNT(*) FILTER (WHERE status IN ('review','failed')) AS review")
            ->groupBy('kind', DB::raw('COALESCE(adm_level, 99)'))
            ->get();

        $out = $this->emptyProgress();
        $byKind = [];
        $byLevel = [];
        $currentKinds = $run->currentKinds();
        foreach ($rows as $row) {
            $kind = $row->kind;
            $level = (int) $row->adm_level;
            $byKind[$kind] ??= array_fill_keys(array_keys($out['ledger']), 0);
            if (in_array($kind, $currentKinds, true)) {
                $byLevel[$level] ??= array_fill_keys(array_keys($out['ledger']), 0);
            }
            foreach (array_keys($out['ledger']) as $metric) {
                $count = (int) $row->$metric;
                $out['ledger'][$metric] += $count;
                $byKind[$kind][$metric] += $count;
                if (in_array($kind, $currentKinds, true)) {
                    $byLevel[$level][$metric] += $count;
                }
            }
        }

        foreach (SimRun::PHASE_KINDS as $phase => $kinds) {
            foreach ($kinds as $kind) {
                if (isset($byKind[$kind])) {
                    $out['stages'][] = [
                        'kind' => $kind, 'phase' => $phase,
                        'label' => self::LABELS[$kind] ?? $kind,
                        'total' => $byKind[$kind]['total'], 'done' => $byKind[$kind]['done'],
                        'running' => $byKind[$kind]['running'], 'review' => $byKind[$kind]['review'],
                        'is_current' => $phase === $run->phase,
                    ];
                }
            }
        }

        $labels = [0 => 'Planet', 1 => 'Countries', 2 => 'States / Provinces',
            3 => 'Counties', 4 => 'Municipalities', 5 => 'Townships',
            6 => 'Neighborhoods', 99 => 'Other'];
        ksort($byLevel);
        foreach ($byLevel as $level => $counts) {
            $counts['pending'] = max(0, $counts['total'] - $counts['done'] - $counts['review'] - $counts['running']);
            $out['layers'][] = [
                'key' => "level:{$level}", 'adm_level' => $level,
                'label' => $labels[$level] ?? "Level {$level}",
                ...$counts,
                'status' => $counts['done'] >= $counts['total'] ? 'done'
                    : ($counts['running'] > 0 || $counts['done'] > 0 ? 'running' : 'pending'),
            ];
        }
        $out['rate'] = $this->computeWindowedRate($run);
        $out['snapshot_at'] = now()->toIso8601String();
        $out['computed_ts'] = now()->timestamp;
        $out['snapshot_stale'] = false;
        $out['snapshot_state'] = 'ready';

        return $out;
    }

    /** The rate window, matching the Step 4 page: items finished in the last 10 min. */
    public const RATE_WINDOW_SECS = 600;

    /** Human labels per item kind — the single owner (W7 item 10). */
    public const LABELS = [
        'profile_research' => 'Researching localities',
        'cohort_scope' => 'Deciding who lives where',
        'identity_batch' => 'Minting people',
        'election_scope' => 'Calling elections',
        'count_election' => 'Counting ballots',
        'seat_scope' => 'Seating representatives',
        'governance_scope' => 'Growing chambers',
        'judiciary_scope' => 'Seating courts',
        'civics_scope' => 'Modelling civic life',
        'training_scope' => 'Training the fleet',
        'stipend_scope' => 'Paying the civic stipend',
        'verify_scope' => 'Verifying the world',
    ];

    /** The one unfinished run, else the newest — the same one the pump acts on. */
    public function activeOrLatestRun(): ?SimRun
    {
        return SimRun::query()
            ->whereIn('status', ['queued', 'running', 'halted'])
            ->orderBy('created_at')
            ->first()
            ?? SimRun::query()->orderByDesc('created_at')->first();
    }

    /** Stage bars from the shared progress sample, in phase order. */
    public function stages(SimRun $run): array
    {
        return $this->progress($run)['stages'];
    }

    /**
     * THE PHASE PLAN — every in-scope phase in DAG order with a status, so the
     * page shows the whole run ahead, not just the phases that have minted a
     * worklist. Infrastructure slots (enumerating, profiling) and the terminal
     * `done` are omitted; `verifying` is kept as the closing check. Status is
     * read off the DAG index of the run's current phase; counts are grafted
     * from the stage bars where a phase's kind has minted items.
     *
     * @param  list<array<string,mixed>>|null  $stages  precomputed stages() to avoid a second query
     * @return array{total:int, phases:list<array<string,mixed>>}
     */
    public function phaseOverview(SimRun $run, ?array $stages = null): array
    {
        $work = array_values(array_filter(
            $run->activePhases(),
            static fn ($p) => ! in_array($p, ['enumerating', 'profiling', 'done'], true)
        ));

        $phaseIndex = array_flip(SimRun::PHASES);
        $curIdx     = $phaseIndex[$run->phase] ?? -1;
        $runDone    = $run->status === 'done';

        $byPhase = [];
        foreach ($stages ?? $this->stages($run) as $s) {
            $byPhase[$s['phase']] = $s;
        }

        // A human label per phase: the phase's first kind's stage label, else a
        // fallback for the kindless closing phase.
        $fallback = ['verifying' => 'Verifying the world'];

        $phases = [];
        $n = 0;
        foreach ($work as $phase) {
            $n++;
            $idx = $phaseIndex[$phase] ?? PHP_INT_MAX;

            if ($runDone || $idx < $curIdx) {
                $status = 'done';
            } elseif ($idx === $curIdx) {
                $status = 'current';
            } else {
                $status = 'pending';
            }

            $kinds = SimRun::PHASE_KINDS[$phase] ?? [];
            $label = $fallback[$phase]
                ?? (isset($kinds[0]) ? (self::LABELS[$kinds[0]] ?? $phase) : ucfirst($phase));

            $st = $byPhase[$phase] ?? null;

            $phases[] = [
                'n'      => $n,
                'phase'  => $phase,
                'label'  => $label,
                'status' => $status,
                'total'  => $st ? (int) $st['total'] : null,
                'done'   => $st ? (int) $st['done'] : null,
            ];
        }

        return ['total' => count($work), 'phases' => $phases];
    }

    /** Totals from the same sample as the stage and layer bars. */
    public function ledger(SimRun $run): array
    {
        return $this->progress($run)['ledger'];
    }

    public function windowedRate(SimRun $run): array
    {
        return $this->progress($run)['rate'];
    }

    /**
     * The WINDOWED rate (the Step 4 rule): items finished in the last 10 minutes,
     * never a cumulative average. finished_at is stamped only when an item
     * settles done, so this counts real recent work and is immune to slow early
     * code and halt/resume gaps.
     *
     * @return array{rate_per_h: ?int, rate_label: ?string}
     */
    private function computeWindowedRate(SimRun $run): array
    {
        if ($run->status !== 'running') {
            return ['rate_per_h' => null, 'rate_label' => null];
        }

        $winDone = (int) DB::table('sim_items')
            ->where('run_id', $run->id)
            ->where('status', 'done')
            ->where('finished_at', '>', now()->subSeconds(self::RATE_WINDOW_SECS))
            ->count();

        if ($winDone <= 0) {
            return ['rate_per_h' => null, 'rate_label' => null];
        }

        return [
            'rate_per_h' => (int) round($winDone / self::RATE_WINDOW_SECS * 3600),
            'rate_label' => 'done/h',
        ];
    }

    /** Current-phase layers from the shared progress sample. */
    public function layers(SimRun $run): array
    {
        return $this->progress($run)['layers'];
    }

    /**
     * THE LANE STRIP — each live worker, what it holds and for how long. Two
     * minutes is the liveness horizon, matching the pump's seeding rule.
     *
     * @return list<array<string,mixed>>
     */
    public function lanes(SimRun $run): array
    {
        return DB::table('sim_worker_leases')
            ->where('run_id', $run->id)
            ->where('last_seen_at', '>', now()->subMinutes(2))
            ->orderByRaw('claim_started_at ASC NULLS LAST, started_at')
            ->get()
            ->map(fn ($w) => [
                'id' => substr((string) $w->id, 0, 8),
                'lane' => $w->lane,
                // A pre-update worker with no claim is unknown, not idle.
                'activity' => in_array($w->activity ?? null, ['acquiring', 'executing', 'waiting'], true)
                    ? $w->activity : ($w->claim_type ? 'executing' : 'unknown'),
                'activity_secs' => ($w->activity_started_at ?? $w->claim_started_at)
                    ? max(0, (int) now()->diffInSeconds(\Carbon\Carbon::parse($w->activity_started_at ?? $w->claim_started_at), true))
                    : null,
                'claim_type' => $w->claim_type,
                'claim_label' => $w->claim_label,
                'claim_secs' => $w->claim_started_at
                    ? max(0, (int) now()->diffInSeconds(\Carbon\Carbon::parse($w->claim_started_at), true))
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * What REFUSED, and why — the honest record of what the world could not
     * build. A run never dies of a failed item; it settles as review and keeps
     * going, so this list is meant to be read.
     *
     * @return list<array<string,mixed>>
     */
    public function reviewItems(SimRun $run): array
    {
        return DB::table('sim_items as s')
            ->leftJoin('jurisdictions as j', 'j.id', '=', 's.jurisdiction_id')
            ->where('s.run_id', $run->id)
            ->whereIn('s.status', ['review', 'failed'])
            ->orderBy('s.position')
            ->limit(50)
            ->get(['s.kind', 's.reason', 'j.name as jurisdiction', 'j.slug as slug', 'j.adm_level as adm_level'])
            ->map(fn ($i) => [
                'kind' => $i->kind,
                'jurisdiction' => $i->jurisdiction ?? '—',
                'slug' => $i->slug,
                'adm_level' => $i->adm_level !== null ? (int) $i->adm_level : null,
                'reason' => $i->reason,
            ])
            ->all();
    }

    /**
     * WHERE THE TIME GOES — per-part timings for this run (SimTimer → sim_timings),
     * largest total first. The diagnostic that shows which stage owns the run's
     * time and how long lanes sit between claims, so a code change can be proven
     * faster or slower part by part (the Step 4 method).
     *
     * @return list<array<string,mixed>>
     */
    public function timings(SimRun $run): array
    {
        return app(SimTimingSnapshot::class)->rows($run);
    }

    /** The sim's worker target for this host (the pool tile). */
    public function pool(): int
    {
        return HostCapacity::autoscaleWorkers();
    }

    /**
     * What the run has PRODUCED — the point of the whole engine.
     *
     * G3 (bound the world poll, ruling A): the headline figures — people,
     * residencies, cohorts, chambers governed — are O(1) counters the pump
     * maintains on the run row (SimWorkerJob::maintainWorldCounters), so a poll
     * reads them instead of scanning users/legislatures/cohorts. The old
     * `WHERE email LIKE 'sim-%@demo.invalid'` scan over users, which grew to
     * millions of rows as the sim populated, is GONE. The four scope figures
     * that need a whole-table read (chambers total, jurisdictions in scope,
     * population and electorate sums) sit behind the SAME scheduler-warmed
     * snapshot the Step 4 rollups use (SetupProgressRollup 'world' kind): the
     * poll only READS the snapshot and serves last-known values with a
     * snapshot_at / snapshot_stale / snapshot_state stamp, never an inline
     * scan. The brief WORLD_TTL cache stays as the second line of defence
     * across concurrent pollers.
     *
     * @return array<string,mixed>
     */
    public function world(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::WORLD_KEY);
        }

        return Cache::remember(self::WORLD_KEY, self::WORLD_TTL, fn () => $this->computeWorld());
    }

    /** @return array<string,mixed> */
    private function computeWorld(): array
    {
        $run = $this->activeOrLatestRun();

        // O(1) counters off the run row (G3). Null-safe: a box that has not
        // applied the additive counters migration reads zeros, never throws.
        $people      = (int) ($run->people_founded ?? 0);
        $residencies = (int) ($run->residencies_founded ?? 0);
        $cohorts     = (int) ($run->cohorts ?? 0);
        $governed    = (int) ($run->chambers_governed ?? 0);
        // LAWFUL INACTIVE places (ruling 2026-09-19): closed DONE with no election.
        $zeroPop     = (int) ($run->places_zero_population ?? 0);
        $tooFew      = (int) ($run->places_too_few_residents ?? 0);

        $scope    = $this->worldScope();
        $chambers = (int) $scope['values']['chambers'];

        return [
            'chambers' => $chambers,
            'chambers_governed' => $governed,
            'chambers_awaiting_election' => max(0, $chambers - $governed),
            'places_zero_population' => $zeroPop,
            'places_too_few_residents' => $tooFew,
            'jurisdictions' => (int) $scope['values']['jurisdictions'],
            'cohorts' => $cohorts,
            'people' => $people,
            'residencies' => $residencies,
            'population_modelled' => (int) $scope['values']['population_modelled'],
            'electorate_modelled' => (int) $scope['values']['electorate_modelled'],
            // The scope figures' provenance (ruling A: serve stale with its
            // timestamp). 'ready' with snapshot_at, or 'computing' until the
            // scheduler / warm job has produced the first copy.
            'scope_snapshot_at'    => $scope['computed_at'],
            'scope_snapshot_stale' => $scope['stale'],
            'scope_snapshot_state' => $scope['state'],
        ];
    }

    /**
     * The scope figures — chambers, jurisdictions, and the modelled population
     * and electorate sums. Ruling A (progress-polling-bounds): these are whole-
     * table reads over the two biggest tables, so they NEVER run on the poll
     * request thread. They live behind the scheduler-warmed snapshot owner
     * (SetupProgressRollup 'world' kind); this method is a pure cache read that
     * marks the viewer and warms a cold miss OFF the request thread, returning
     * last-known values (or zeros while computing) with a stamp. No count(*),
     * no sum() runs here.
     *
     * @return array{values:array<string,int>,computed_at:?string,stale:bool,state:string}
     */
    private function worldScope(): array
    {
        $snap   = app(SetupProgressRollup::class)->readOrWarm('world');
        $values = $snap['values'] ?? SetupProgressRollup::emptyValues('world');

        return [
            'values'      => $values,
            'computed_at' => $snap['computed_at'],
            'stale'       => $snap['stale'],
            'state'       => $snap['state'],
        ];
    }
}
