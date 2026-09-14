<?php

namespace App\Services\Setup;

use App\Jobs\Setup\WarmSetupRollupJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * THE ONE SNAPSHOT OWNER for the setup progress polls (G3).
 *
 * The Step 4 / Step 2 wizard polls used to run whole-world statements on every
 * request: the provision_ledger COUNT FILTER aggregate, count(*) legislatures,
 * the windowed finished_at count, the ADM-level GROUP BY, the newest-25 review
 * join, the four Step 4 summary counts, and the jurisdictions-per-level count.
 * On box E (951k jurisdictions, 940k legislatures) each poll paid a full scan.
 *
 * This service is the SAME warm-path the autoscale Step 3 dashboard already
 * uses (SetupController::autoscaleProgress), generalised to one owner:
 *
 *   - A scheduled refresher (routes/console.php, every minute) recomputes each
 *     rollup into a cache key with a computed_at stamp WHILE A VIEWER IS SEEN.
 *   - The HTTP handlers only READ the key. read() never scans; it returns
 *     {values, computed_at, stale}. A missing key returns values=null with
 *     state='computing' — the handler serves zeros / last-known, never an
 *     inline scan and never a usleep spin.
 *   - readOrWarm() marks the viewer and, on a cold miss, dispatches a one-shot
 *     queued warm (guarded so concurrent pollers dispatch once). The first
 *     computation happens in the scheduler tick or that queued job, never in
 *     the request thread.
 *
 * Host-derived sizing is untouched — this bounds the poll, not the engine.
 */
class SetupProgressRollup
{
    /** A viewer counts as present this long after their last poll. */
    public const VIEWER_TTL = 180;

    /** Scheduler cadence (seconds). A copy older than 2x this reads as stale. */
    public const FRESH_SECONDS = 60;

    /** How long a computed copy is retained (well past the fresh window). */
    public const RETAIN_SECONDS = 900;

    /** The rollup kinds this owner maintains. */
    public const KINDS = ['step4', 'summary', 'jurisdictions', 'world'];

    private function key(string $kind): string
    {
        return "setup.rollup.{$kind}";
    }

    private function viewerKey(string $kind): string
    {
        return "setup.rollup.viewer.{$kind}";
    }

    /** Mark that a viewer polled this kind, so the scheduler keeps it warm. */
    public function markViewer(string $kind): void
    {
        Cache::put($this->viewerKey($kind), now()->timestamp, self::VIEWER_TTL);
    }

    /** Is a viewer present for this kind right now? */
    public function hasViewer(string $kind): bool
    {
        $seen = (int) Cache::get($this->viewerKey($kind), 0);

        return $seen > 0 && (now()->timestamp - $seen) <= self::VIEWER_TTL;
    }

    /**
     * Read a rollup. NEVER scans. A present copy returns its values plus a
     * computed_at stamp and a stale flag; a missing copy returns values=null
     * and state='computing'.
     *
     * @return array{values:?array<string,mixed>,computed_at:?string,stale:bool,state:string}
     */
    public function read(string $kind): array
    {
        $entry = Cache::get($this->key($kind));
        if (! is_array($entry) || ! array_key_exists('values', $entry)) {
            return ['values' => null, 'computed_at' => null, 'stale' => true, 'state' => 'computing'];
        }

        $ts    = (int) ($entry['computed_ts'] ?? 0);
        $stale = $ts === 0 || (now()->timestamp - $ts) > (2 * self::FRESH_SECONDS);

        return [
            'values'      => $entry['values'],
            'computed_at' => $entry['computed_at'] ?? null,
            'stale'       => $stale,
            'state'       => 'ready',
        ];
    }

    /**
     * Read, marking the viewer and warming a cold miss off the request thread.
     * Returns the same shape as read(). Never scans in the caller's request.
     *
     * @return array{values:?array<string,mixed>,computed_at:?string,stale:bool,state:string}
     */
    public function readOrWarm(string $kind): array
    {
        $this->markViewer($kind);
        $out = $this->read($kind);

        // Cold miss: dispatch exactly one warm (Cache::add is atomic, so a
        // burst of pollers dispatches a single job). The job computes on the
        // queue; the request returns 'computing' and the next poll finds it.
        if ($out['state'] === 'computing'
            && Cache::add($this->key($kind).'.warming', 1, self::FRESH_SECONDS)) {
            WarmSetupRollupJob::dispatch($kind);
        }

        return $out;
    }

    /**
     * Compute and store one rollup. Called by the scheduler and the warm job
     * ONLY — this is where the whole-world scans live now, off the poll path.
     *
     * @return array<string,mixed> the stored entry
     */
    public function refresh(string $kind): array
    {
        $values = match ($kind) {
            'step4'         => $this->computeStep4(),
            'summary'       => $this->computeSummary(),
            'jurisdictions' => $this->computeJurisdictions(),
            'world'         => $this->computeWorldScope(),
            default         => throw new \InvalidArgumentException("unknown setup rollup: {$kind}"),
        };

        $entry = [
            'values'      => $values,
            'computed_at' => now()->toIso8601String(),
            'computed_ts' => now()->timestamp,
        ];
        Cache::put($this->key($kind), $entry, self::RETAIN_SECONDS);
        Cache::forget($this->key($kind).'.warming');

        return $entry;
    }

    /** Scheduler entry: warm every rollup that has a live viewer. */
    public function refreshWatched(): void
    {
        foreach (self::KINDS as $kind) {
            if ($this->hasViewer($kind)) {
                $this->refresh($kind);
            }
        }
    }

    // ─── the whole-world computations (the ONLY place they run now) ──────────

    /**
     * The Step 4 heavy aggregates: the provision_ledger 9-filter counter, the
     * legislatures scalar, the 10-minute windowed finished count, the built
     * per-layer bars, and the newest-25 review list.
     *
     * @return array<string,mixed>
     */
    public function computeStep4(): array
    {
        $ledger = DB::table('provision_ledger')->selectRaw("
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE status = 'skipped') AS skipped,
            COUNT(*) FILTER (WHERE stage >= 1) AS shells_done,
            COUNT(*) FILTER (WHERE status = 'done') AS units_done,
            COUNT(*) FILTER (WHERE status = 'review') AS review,
            COUNT(*) FILTER (WHERE status = 'running' AND stage = 0) AS shells_running,
            COUNT(*) FILTER (WHERE status = 'running' AND stage = 1) AS units_running,
            COUNT(*) FILTER (WHERE status = 'pending' AND stage = 0) AS shells_pending,
            COUNT(*) FILTER (WHERE status = 'pending' AND stage = 1) AS units_pending
        ")->first();
        $ledger = array_map('intval', (array) $ledger);

        $totalLegislatures = (int) DB::scalar('SELECT count(*) FROM legislatures WHERE deleted_at IS NULL');

        // The 10-minute windowed founded count. Recomputed each refresh, so it
        // trails the true window by at most the scheduler cadence — the ETA
        // that reads it is display-only.
        $windowSecs = 600;
        $winDone = (int) DB::table('provision_ledger')
            ->where('status', 'done')
            ->where('finished_at', '>', now()->subSeconds($windowSecs))
            ->count();

        return [
            'ledger'             => $ledger,
            'total_legislatures' => $totalLegislatures,
            'window_secs'        => $windowSecs,
            'win_done'           => $winDone,
            'layers'             => $this->buildLayers(),
            'review'             => $this->buildReview(),
        ];
    }

    /**
     * SEGMENTED PER-LAYER BARS: one row per ADM layer over
     * provision_ledger.adm_level, each split seated | shelled | review.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildLayers(): array
    {
        $layerRows = DB::table('provision_ledger')
            ->selectRaw("
                COALESCE(adm_level, 99) AS adm_level,
                COUNT(*)                                    AS total,
                COUNT(*) FILTER (WHERE status = 'skipped')  AS skipped,
                COUNT(*) FILTER (WHERE stage >= 1)          AS shells_done,
                COUNT(*) FILTER (WHERE status = 'done')     AS units_done,
                COUNT(*) FILTER (WHERE status = 'running')  AS running,
                COUNT(*) FILTER (WHERE status = 'review')   AS review
            ")
            ->groupBy(DB::raw('COALESCE(adm_level, 99)'))
            ->orderBy('adm_level')
            ->get();

        $layerLabels = [
            0 => 'Planet', 1 => 'Countries', 2 => 'States / Provinces',
            3 => 'Counties', 4 => 'Municipalities', 5 => 'Townships',
            6 => 'Neighborhoods', 99 => 'Other',
        ];

        $layers = [];
        foreach ($layerRows as $r) {
            $lvl     = (int) $r->adm_level;
            $tot     = (int) $r->total;
            $sk      = (int) $r->skipped;
            $workL   = max(0, $tot - $sk);
            $seated  = (int) $r->units_done;
            $review  = (int) $r->review;
            $shelled = max(0, (int) $r->shells_done - $seated - $review);
            $shelled = min($shelled, max(0, $workL - $seated - $review));
            $pending = max(0, $workL - $seated - $shelled - $review);
            $status  = $workL === 0 ? 'skipped'
                : ($seated >= $workL ? 'done'
                : ((int) $r->running > 0 || (int) $r->shells_done > 0 ? 'running' : 'pending'));
            $layers[] = [
                'key'       => "level:{$lvl}",
                'adm_level' => $lvl,
                'label'     => $layerLabels[$lvl] ?? "Level {$lvl}",
                'total'     => $tot,
                'skipped'   => $sk,
                'work'      => $workL,
                'seated'    => $seated,
                'shelled'   => $shelled,
                'running'   => (int) $r->running,
                'review'    => $review,
                'pending'   => $pending,
                'status'    => $status,
            ];
        }

        return $layers;
    }

    /**
     * The newest-25 review list (join to jurisdictions for the name/slug), the
     * one bounded read that still touched provision_ledger on the poll path.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildReview(): array
    {
        return DB::table('provision_ledger as pl')
            ->join('jurisdictions as j', 'j.id', '=', 'pl.jurisdiction_id')
            ->where('pl.status', 'review')
            ->orderByDesc('pl.est_cost')
            ->limit(25)
            ->get(['pl.legislature_id', 'j.name', 'j.slug', 'j.adm_level', 'pl.reason'])
            ->map(fn ($r) => [
                'legislature_id' => (string) $r->legislature_id,
                'name'           => $r->name,
                'slug'           => $r->slug,
                'adm_level'      => $r->adm_level,
                'reason'         => $r->reason,
            ])->values()->all();
    }

    /**
     * The four Step 4 summary counts.
     *
     * @return array<string,int>
     */
    public function computeSummary(): array
    {
        return [
            'legislatures'         => (int) DB::table('legislatures')->whereNull('deleted_at')->count(),
            'districts'            => (int) DB::table('legislature_districts')->whereNull('deleted_at')->count(),
            'existing_executives'  => (int) DB::table('executives')->whereNull('deleted_at')->count(),
            'existing_judiciaries' => (int) DB::table('judiciaries')->whereNull('deleted_at')->count(),
        ];
    }

    /**
     * The jurisdictions-per-level count (totals, with-population, sum) that
     * both the Step 2 map poll and detectStep1 read.
     *
     * @return array<string,mixed>
     */
    public function computeJurisdictions(): array
    {
        $rows = DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->select(
                'adm_level',
                DB::raw('count(*) as c'),
                DB::raw('count(*) FILTER (WHERE population IS NOT NULL AND population > 0) as with_pop'),
                DB::raw('COALESCE(SUM(population) FILTER (WHERE population > 0), 0) as sum_pop'),
            )
            ->groupBy('adm_level')
            ->orderBy('adm_level')
            ->get();

        $labels = [
            0 => 'Planet',
            1 => 'Countries',
            2 => 'States / Provinces',
            3 => 'Counties',
            4 => 'Municipalities',
            5 => 'Townships',
            6 => 'Neighborhoods',
        ];

        $byLevel      = [];
        $byLevelMap   = [];
        $total        = 0;
        $totalWithPop = 0;
        $totalSumPop  = 0;
        foreach ($rows as $r) {
            $lvl     = (int) $r->adm_level;
            $count   = (int) $r->c;
            $withPop = (int) $r->with_pop;
            $sumPop  = (int) $r->sum_pop;
            $byLevel[]        = [
                'level'    => $lvl,
                'count'    => $count,
                'with_pop' => $withPop,
                'sum_pop'  => $sumPop,
                'label'    => $labels[$lvl] ?? ('Level ' . $lvl),
            ];
            $byLevelMap[$lvl] = $count;
            $total           += $count;
            $totalWithPop    += $withPop;
            $totalSumPop     += $sumPop;
        }

        return [
            'adm0'           => $byLevelMap[0] ?? 0,
            'adm1'           => $byLevelMap[1] ?? 0,
            'adm2'           => $byLevelMap[2] ?? 0,
            'total'          => $total,
            'total_with_pop' => $totalWithPop,
            'total_sum_pop'  => $totalSumPop,
            'by_level'       => $byLevel,
        ];
    }

    /**
     * The sim world scope figures — chambers, jurisdictions, and the modelled
     * population and electorate sums. Ruling A (progress-polling-bounds): these
     * were four inline whole-table scans on the Step 5 poll request thread
     * (count over legislatures 940k, count over jurisdictions 951k, two sums
     * over jurisdiction_cohorts). They live HERE now, off the poll path, warmed
     * by the scheduler while a viewer polls; SimSnapshot::worldScope only READS
     * the snapshot and serves last-known values with a stamp.
     *
     * @return array<string,int>
     */
    public function computeWorldScope(): array
    {
        return [
            'chambers'            => (int) DB::table('legislatures')->whereNull('deleted_at')->count(),
            'jurisdictions'       => (int) DB::table('jurisdictions')->whereNull('deleted_at')->count(),
            'population_modelled' => (int) DB::table('jurisdiction_cohorts')->sum('population'),
            'electorate_modelled' => (int) DB::table('jurisdiction_cohorts')->sum('electorate'),
        ];
    }

    /**
     * The zeroed shape a handler serves while a rollup is still computing, so
     * the payload keys never change between 'computing' and 'ready'.
     *
     * @return array<string,mixed>
     */
    public static function emptyValues(string $kind): array
    {
        return match ($kind) {
            'step4' => [
                'ledger' => [
                    'total' => 0, 'skipped' => 0, 'shells_done' => 0, 'units_done' => 0,
                    'review' => 0, 'shells_running' => 0, 'units_running' => 0,
                    'shells_pending' => 0, 'units_pending' => 0,
                ],
                'total_legislatures' => 0,
                'window_secs'        => 600,
                'win_done'           => 0,
                'layers'             => [],
                'review'             => [],
            ],
            'summary' => [
                'legislatures' => 0, 'districts' => 0,
                'existing_executives' => 0, 'existing_judiciaries' => 0,
            ],
            'jurisdictions' => [
                'adm0' => 0, 'adm1' => 0, 'adm2' => 0,
                'total' => 0, 'total_with_pop' => 0, 'total_sum_pop' => 0, 'by_level' => [],
            ],
            'world' => [
                'chambers' => 0, 'jurisdictions' => 0,
                'population_modelled' => 0, 'electorate_modelled' => 0,
            ],
            default => [],
        };
    }
}
