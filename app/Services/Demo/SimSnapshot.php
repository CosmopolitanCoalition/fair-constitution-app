<?php

namespace App\Services\Demo;

use App\Models\SimRun;
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
 * THE CHEAP / EXPENSIVE SPLIT (the Step 4 anti-tax lesson). The stage bars are a
 * fresh index-only GROUP BY on `sim_items` and run on every poll — real numbers,
 * never the pump's once-a-minute counters. `world()` is the opposite: it scans
 * legislatures, users (a `sim-%@demo.invalid` LIKE that grows to millions as the
 * sim populates) and two heavy district joins, seconds now and tens of seconds
 * at full population. An open progress page polling that every few seconds would
 * steal the same disk the run needs, so `world()` is cached briefly and every
 * poller shares the one computation.
 */
class SimSnapshot
{
    /** How long the expensive world() aggregate is reused across pollers. */
    public const WORLD_TTL = 10;

    public const WORLD_KEY = 'sim:world';

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

    /**
     * One bar per item kind — the fresh GROUP BY that IS the progress store,
     * ordered by the phase DAG so it reads top-to-bottom as the run proceeds.
     *
     * @return list<array<string,mixed>>
     */
    public function stages(SimRun $run): array
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

    /**
     * One fresh aggregate over the whole worklist — the tiles' Total / Done /
     * Running / Review. Index-only; cheap on every poll.
     *
     * @return array<string,int>
     */
    public function ledger(SimRun $run): array
    {
        $row = DB::table('sim_items')
            ->where('run_id', $run->id)
            ->selectRaw("
                COUNT(*)                                              AS total,
                COUNT(*) FILTER (WHERE status = 'done')               AS done,
                COUNT(*) FILTER (WHERE status = 'running')            AS running,
                COUNT(*) FILTER (WHERE status = 'pending')            AS pending,
                COUNT(*) FILTER (WHERE status IN ('review','failed')) AS review
            ")
            ->first();

        return array_map('intval', (array) $row);
    }

    /**
     * The WINDOWED rate (the Step 4 rule): items finished in the last 10 minutes,
     * never a cumulative average. finished_at is stamped only when an item
     * settles done, so this counts real recent work and is immune to slow early
     * code and halt/resume gaps.
     *
     * @return array{rate_per_h: ?int, rate_label: ?string}
     */
    public function windowedRate(SimRun $run): array
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

    /**
     * SEGMENTED PER-LAYER BARS over sim_items.adm_level — one bar per ADM layer,
     * done | running | review | the void, the same shape the Step 4 page uses.
     *
     * @return list<array<string,mixed>>
     */
    public function layers(SimRun $run): array
    {
        $rows = DB::table('sim_items')
            ->where('run_id', $run->id)
            ->selectRaw("
                COALESCE(adm_level, 99) AS adm_level,
                COUNT(*)                                              AS total,
                COUNT(*) FILTER (WHERE status = 'done')               AS done,
                COUNT(*) FILTER (WHERE status = 'running')            AS running,
                COUNT(*) FILTER (WHERE status IN ('review','failed')) AS review
            ")
            ->groupBy(DB::raw('COALESCE(adm_level, 99)'))
            ->orderBy('adm_level')
            ->get();

        $labels = [
            0 => 'Planet', 1 => 'Countries', 2 => 'States / Provinces',
            3 => 'Counties', 4 => 'Municipalities', 5 => 'Townships',
            6 => 'Neighborhoods', 99 => 'Other',
        ];

        $layers = [];
        foreach ($rows as $r) {
            $lvl = (int) $r->adm_level;
            $tot = (int) $r->total;
            $done = (int) $r->done;
            $review = (int) $r->review;
            $running = (int) $r->running;
            $pending = max(0, $tot - $done - $review - $running);
            $status = $done >= $tot ? 'done'
                : ($running > 0 || $done > 0 ? 'running' : 'pending');

            $layers[] = [
                'key' => "level:{$lvl}",
                'adm_level' => $lvl,
                'label' => $labels[$lvl] ?? "Level {$lvl}",
                'total' => $tot,
                'done' => $done,
                'running' => $running,
                'review' => $review,
                'pending' => $pending,
                'status' => $status,
            ];
        }

        return $layers;
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
        return DB::table('sim_timings')
            ->where('run_id', (string) $run->id)
            ->get()
            ->map(fn ($t) => [
                'part' => $t->part,
                'count' => (int) $t->count,
                'avg_ms' => $t->count > 0 ? round($t->total_us / $t->count / 1000, 2) : 0.0,
                'max_ms' => round($t->max_us / 1000, 2),
                'total_s' => round($t->total_us / 1_000_000, 1),
            ])
            ->sortByDesc('total_s')
            ->values()
            ->all();
    }

    /** The sim's worker target for this host (the pool tile). */
    public function pool(): int
    {
        return HostCapacity::autoscaleWorkers();
    }

    /**
     * What the run has PRODUCED — the point of the whole engine. EXPENSIVE
     * (heavy joins + a growing sim-user LIKE scan), so cached briefly and shared
     * across every poller. Never call this on the fast path uncached.
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
        $chambers = (int) DB::table('legislatures')->whereNull('deleted_at')->count();

        $governed = (int) DB::table('legislatures as l')
            ->whereNull('l.deleted_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('legislature_members as m')
                    ->whereColumn('m.legislature_id', 'l.id')
                    ->whereIn('m.status', ['elected', 'seated'])
                    ->whereNull('m.deleted_at');
            })
            ->count();

        return [
            'chambers' => $chambers,
            'chambers_governed' => $governed,
            'chambers_awaiting_election' => max(0, $chambers - $governed),
            'jurisdictions' => (int) DB::table('jurisdictions')->whereNull('deleted_at')->count(),
            'cohorts' => (int) DB::table('jurisdiction_cohorts')->count(),
            // 'sim-%' not '@demo.invalid': the reserved namespace is shared with
            // other fixtures, and a headline number must count only what THIS
            // engine made.
            'people' => (int) DB::table('users')->where('email', 'like', 'sim-%@demo.invalid')->count(),
            'residencies' => (int) DB::table('residency_confirmations')->where('is_active', true)->count(),
            'population_modelled' => (int) DB::table('jurisdiction_cohorts')->sum('population'),
            'electorate_modelled' => (int) DB::table('jurisdiction_cohorts')->sum('electorate'),
        ];
    }
}
