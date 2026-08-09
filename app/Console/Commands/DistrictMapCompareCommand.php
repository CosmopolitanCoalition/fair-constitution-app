<?php

namespace App\Console\Commands;

use App\Services\DistrictingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Compare two district maps of the same legislature for MATH HEALTH, and
 * record the result so it can be benchmarked (operator ask 2026-08-09: "I want
 * the manual and auto comparison noted and recorded so it can be benchmarked",
 * and future fixes measured against it).
 *
 * The comparison is deliberately made of the same quantities the engine's own
 * comparator ranks, in the same priority order, so "better" here means the same
 * thing it means inside scoreRank():
 *
 *   1. DRIFT — Σ seats vs the scope's lawful budget. Drift is ALWAYS wrong
 *      (ruling 2026-07-26): the chamber size is fixed by the cube-root law, so
 *      a total that misses the budget leaves seats unfillable or unallotted.
 *      This is the first key and it is not tradeable.
 *   2. BAND — districts below the floor or above the ceiling. Constitutional.
 *   3. EQUALITY — population deviation per seat against the scope's realized
 *      quota, average and worst.
 *   4. WHOLENESS — districts flagged non-contiguous, and total geometry parts.
 *   5. SHAPE — convex-hull ratio (area / hull area; 1.0 is a convex blob).
 *
 * Reads only. No PostGIS: every figure comes from columns recomputeDistrict()
 * already stored, so this is cheap enough to run on the full planet and safe to
 * run mid-sweep. The scope roster is fetched first and walked in bounded units
 * rather than one planet-wide join (THE ETL PARADIGM).
 */
class DistrictMapCompareCommand extends Command
{
    protected $signature = 'districts:compare
        {legislature : legislature id, or the slug/name of its root jurisdiction}
        {--a= : baseline map id or name fragment (default: the active map)}
        {--b= : candidate map id or name fragment (default: newest draft)}
        {--scope= : limit to one scope jurisdiction (slug, name or id)}
        {--worst=15 : how many worst-divergence scopes to print}
        {--save : write the full per-scope report to storage/app/map-benchmarks/}';

    protected $description = 'Compare two district maps for math health (drift, band, equality, wholeness, shape) and record the benchmark';

    public function handle(DistrictingService $districting): int
    {
        $leg = $this->resolveLegislature((string) $this->argument('legislature'));
        if ($leg === null) {
            $this->error('Legislature not found.');

            return self::FAILURE;
        }

        $mapA = $this->resolveMap($leg->id, $this->option('a'), 'active');
        $mapB = $this->resolveMap($leg->id, $this->option('b'), 'draft');
        if ($mapA === null || $mapB === null) {
            $this->error('Could not resolve both maps. Available:');
            foreach ($this->maps($leg->id) as $m) {
                $this->line(sprintf('  %s  %-40s %s', $m->id, $m->name, $m->status));
            }

            return self::FAILURE;
        }
        if ($mapA->id === $mapB->id) {
            $this->error('Both selectors resolved to the same map.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line("  <options=bold>A (baseline)</>  {$mapA->name}  [{$mapA->status}]");
        $this->line("  <options=bold>B (candidate)</> {$mapB->name}  [{$mapB->status}]");

        // Bounded input: the scope roster first, then one small aggregate per
        // scope — never one planet-wide join.
        $scopes = $this->scopeRoster($leg->id, [$mapA->id, $mapB->id]);
        if ($scopes === []) {
            $this->warn('Neither map has any districts.');

            return self::SUCCESS;
        }
        $this->line('  scopes with districts in either map: ' . count($scopes));
        $this->line('');

        $rows = [];
        $bar  = $this->output->createProgressBar(count($scopes));
        $bar->start();
        foreach ($scopes as $scopeId) {
            // THE SCOPE'S OWN BUDGET IS THE NON-GIANT BUDGET. A scope with
            // giant children draws districts for only part of its allocation —
            // each giant's seats are lawfully delegated to that giant's own
            // sub-scope, which appears in this roster in its own right. Judging
            // the parent against its FULL budget reports a phantom undercount
            // (San Marino read -10 while the map totalled exactly 32) and would
            // make this benchmark lie in the one place it must not.
            $budget = $districting->computeSeatBudget($scopeId, (string) $leg->id);
            $delegated = 0;
            if ($budget !== null) {
                foreach ($districting->giantChildrenForScope($scopeId, (string) $leg->id) as $giantSeats) {
                    $delegated += (int) $giantSeats;
                }
                $budget = max(0, $budget - $delegated);
            }
            $a = $this->scopeStats($leg->id, $mapA->id, $scopeId, $budget);
            $b = $this->scopeStats($leg->id, $mapB->id, $scopeId, $budget);
            if ($a === null && $b === null) {
                $bar->advance();
                continue;
            }
            $rows[] = [
                'scope_id'   => $scopeId,
                'scope_name' => $this->scopeName($scopeId),
                'budget'     => $budget,          // this scope's OWN (non-giant) share
                'delegated'  => $delegated,       // seats handed to giant sub-scopes
                'a'          => $a,
                'b'          => $b,
            ];
            $bar->advance();
        }
        $bar->finish();
        $this->line('');
        $this->line('');

        $this->renderTotals($rows);
        $this->renderWorst($rows, (int) $this->option('worst'));

        if ($this->option('save')) {
            $path = $this->save($leg, $mapA, $mapB, $rows);
            $this->line('');
            $this->info("  benchmark written: {$path}");
            $this->line('  Re-run after a fix and diff the two files to see whether it moved the numbers.');
        }
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * One scope's health under one map. Null when that map draws nothing here.
     *
     * @return array<string, mixed>|null
     */
    private function scopeStats(string $legId, string $mapId, string $scopeId, ?int $budget): ?array
    {
        $d = DB::table('legislature_districts')
            ->where('legislature_id', $legId)
            ->where('map_id', $mapId)
            ->where('jurisdiction_id', $scopeId)
            ->whereNull('deleted_at')
            ->get(['seats', 'actual_population', 'is_contiguous', 'num_geom_parts', 'convex_hull_ratio']);
        if ($d->isEmpty()) {
            return null;
        }

        $seats = (int) $d->sum('seats');
        $pop   = (int) $d->sum('actual_population');
        // The REALIZED quota: population actually enclosed per seat actually
        // seated. Deviation against this measures internal equality; whether
        // the seat total itself is lawful is the separate drift key.
        $quota = $seats > 0 ? $pop / $seats : 0.0;

        $devs = [];
        foreach ($d as $row) {
            $s = max(1, (int) $row->seats);
            if ($quota > 0.0) {
                $devs[] = abs(((int) $row->actual_population / $s - $quota) / $quota) * 100.0;
            }
        }
        $hulls = $d->filter(fn ($r) => $r->convex_hull_ratio !== null)
            ->map(fn ($r) => (float) $r->convex_hull_ratio)->all();

        return [
            'districts'   => $d->count(),
            'seats'       => $seats,
            'drift'       => $budget !== null ? $seats - $budget : null,
            'below_floor' => $d->filter(fn ($r) => (int) $r->seats < 5)->count(),
            'above_ceil'  => $d->filter(fn ($r) => (int) $r->seats > 9)->count(),
            'avg_dev_pct' => $devs === [] ? 0.0 : round(array_sum($devs) / count($devs), 3),
            'max_dev_pct' => $devs === [] ? 0.0 : round(max($devs), 3),
            'broken'      => $d->filter(fn ($r) => $r->is_contiguous === false)->count(),
            'geom_parts'  => (int) $d->sum('num_geom_parts'),
            'avg_hull'    => $hulls === [] ? null : round(array_sum($hulls) / count($hulls), 4),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function renderTotals(array $rows): void
    {
        $sum = function (string $side, string $key) use ($rows) {
            $t = 0;
            foreach ($rows as $r) {
                $t += (int) ($r[$side][$key] ?? 0);
            }

            return $t;
        };
        $absDrift = function (string $side) use ($rows) {
            $t = 0;
            foreach ($rows as $r) {
                $t += abs((int) ($r[$side]['drift'] ?? 0));
            }

            return $t;
        };
        $wmean = function (string $side, string $key) use ($rows) {
            $n = 0; $acc = 0.0;
            foreach ($rows as $r) {
                if (($r[$side] ?? null) === null || ($r[$side][$key] ?? null) === null) continue;
                $w = (int) $r[$side]['districts'];
                $acc += (float) $r[$side][$key] * $w;
                $n += $w;
            }

            return $n > 0 ? round($acc / $n, 3) : null;
        };
        $worstDev = function (string $side) use ($rows) {
            $m = 0.0;
            foreach ($rows as $r) {
                $m = max($m, (float) ($r[$side]['max_dev_pct'] ?? 0));
            }

            return round($m, 3);
        };

        $fmt = fn ($v) => $v === null ? '—' : (is_float($v) ? number_format($v, 3) : number_format($v));
        $verdict = function ($a, $b, bool $lowerIsBetter = true) {
            if ($a === null || $b === null || $a == $b) return '=';
            $bBetter = $lowerIsBetter ? $b < $a : $b > $a;

            return $bBetter ? 'B better' : 'A better';
        };

        $this->line('  <options=bold>TOTALS — the doctrine keys, in the comparator\'s own priority order</>');
        $this->table(
            ['', 'A (baseline)', 'B (candidate)', ''],
            [
                ['1. SEAT DRIFT |Σ|  (must be 0)', $fmt($absDrift('a')), $fmt($absDrift('b')), $verdict($absDrift('a'), $absDrift('b'))],
                ['2. below floor (<5)', $fmt($sum('a', 'below_floor')), $fmt($sum('b', 'below_floor')), $verdict($sum('a', 'below_floor'), $sum('b', 'below_floor'))],
                ['2. above ceiling (>9)', $fmt($sum('a', 'above_ceil')), $fmt($sum('b', 'above_ceil')), $verdict($sum('a', 'above_ceil'), $sum('b', 'above_ceil'))],
                ['3. avg deviation %', $fmt($wmean('a', 'avg_dev_pct')), $fmt($wmean('b', 'avg_dev_pct')), $verdict($wmean('a', 'avg_dev_pct'), $wmean('b', 'avg_dev_pct'))],
                ['3. worst deviation %', $fmt($worstDev('a')), $fmt($worstDev('b')), $verdict($worstDev('a'), $worstDev('b'))],
                ['4. non-contiguous', $fmt($sum('a', 'broken')), $fmt($sum('b', 'broken')), $verdict($sum('a', 'broken'), $sum('b', 'broken'))],
                ['4. geometry parts', $fmt($sum('a', 'geom_parts')), $fmt($sum('b', 'geom_parts')), $verdict($sum('a', 'geom_parts'), $sum('b', 'geom_parts'))],
                ['5. avg hull ratio (higher=better)', $fmt($wmean('a', 'avg_hull')), $fmt($wmean('b', 'avg_hull')), $verdict($wmean('a', 'avg_hull'), $wmean('b', 'avg_hull'), false)],
                ['— districts', $fmt($sum('a', 'districts')), $fmt($sum('b', 'districts')), ''],
                ['— seats seated', $fmt($sum('a', 'seats')), $fmt($sum('b', 'seats')), ''],
            ]
        );
    }

    /** @param list<array<string, mixed>> $rows */
    private function renderWorst(array $rows, int $limit): void
    {
        $scored = [];
        foreach ($rows as $r) {
            $ad = abs((int) ($r['a']['drift'] ?? 0));
            $bd = abs((int) ($r['b']['drift'] ?? 0));
            if ($ad === 0 && $bd === 0
                && (float) ($r['a']['max_dev_pct'] ?? 0) === (float) ($r['b']['max_dev_pct'] ?? 0)) {
                continue;
            }
            $scored[] = [$r, max($ad, $bd) * 1000 + abs((float) ($r['a']['max_dev_pct'] ?? 0) - (float) ($r['b']['max_dev_pct'] ?? 0))];
        }
        if ($scored === []) {
            $this->info('  No scope differs on drift or worst-deviation — the two maps are equally healthy by those keys.');

            return;
        }
        usort($scored, fn ($x, $y) => $y[1] <=> $x[1]);

        $this->line('');
        $this->line('  <options=bold>WHERE THEY DIVERGE</> (worst first — drift dominates)');
        $body = [];
        foreach (array_slice($scored, 0, max(1, $limit)) as [$r, $_]) {
            $body[] = [
                mb_strimwidth((string) $r['scope_name'], 0, 28, '…'),
                $r['budget'] ?? '—',
                $this->cell($r['a'], 'drift'), $this->cell($r['b'], 'drift'),
                $this->cell($r['a'], 'max_dev_pct'), $this->cell($r['b'], 'max_dev_pct'),
                $this->cell($r['a'], 'broken'), $this->cell($r['b'], 'broken'),
            ];
        }
        $this->table(['scope', 'budget', 'A drift', 'B drift', 'A worst%', 'B worst%', 'A brk', 'B brk'], $body);
    }

    private function cell(?array $side, string $key): string
    {
        if ($side === null) return '(none)';
        $v = $side[$key] ?? null;

        return $v === null ? '—' : (string) $v;
    }

    /** @param list<array<string, mixed>> $rows */
    private function save(object $leg, object $mapA, object $mapB, array $rows): string
    {
        $dir = storage_path('app/map-benchmarks');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . now()->format('Ymd-His') . '-' . substr((string) $leg->id, 0, 8) . '.json';
        file_put_contents($path, json_encode([
            'generated_at'   => now()->toIso8601String(),
            'legislature_id' => (string) $leg->id,
            'map_a'          => ['id' => $mapA->id, 'name' => $mapA->name, 'status' => $mapA->status],
            'map_b'          => ['id' => $mapB->id, 'name' => $mapB->name, 'status' => $mapB->status],
            'scopes'         => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    private function resolveLegislature(string $needle): ?object
    {
        $row = DB::table('legislatures')->where('id', $needle)->whereNull('deleted_at')->first(['id', 'jurisdiction_id', 'type_a_seats']);
        if ($row !== null) {
            return $row;
        }
        $jid = DB::table('jurisdictions')
            ->where(fn ($q) => $q->where('slug', $needle)->orWhere('name', $needle))
            ->whereNull('deleted_at')
            ->value('id');

        return $jid === null ? null : DB::table('legislatures')
            ->where('jurisdiction_id', $jid)->whereNull('deleted_at')
            ->first(['id', 'jurisdiction_id', 'type_a_seats']);
    }

    /** @return list<object> */
    private function maps(string $legId): array
    {
        return DB::table('legislature_district_maps')
            ->where('legislature_id', $legId)->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'status', 'created_at'])->all();
    }

    private function resolveMap(string $legId, ?string $needle, string $fallbackStatus): ?object
    {
        $maps = $this->maps($legId);
        if ($needle !== null && $needle !== '') {
            foreach ($maps as $m) {
                if ((string) $m->id === $needle) return $m;
            }
            foreach ($maps as $m) {
                if (stripos((string) $m->name, $needle) !== false) return $m;
            }

            return null;
        }
        foreach ($maps as $m) {
            if ($m->status === $fallbackStatus) return $m;
        }

        return null;
    }

    /** @return list<string> */
    private function scopeRoster(string $legId, array $mapIds): array
    {
        $q = DB::table('legislature_districts')
            ->where('legislature_id', $legId)
            ->whereIn('map_id', $mapIds)
            ->whereNull('deleted_at')
            ->whereNotNull('jurisdiction_id');

        $only = $this->option('scope');
        if ($only !== null && $only !== '') {
            $sid = DB::table('jurisdictions')
                ->where(fn ($w) => $w->where('id', $only)->orWhere('slug', $only)->orWhere('name', $only))
                ->value('id');
            if ($sid === null) {
                return [];
            }
            $q->where('jurisdiction_id', $sid);
        }

        return $q->distinct()->orderBy('jurisdiction_id')->pluck('jurisdiction_id')
            ->map(fn ($v) => (string) $v)->all();
    }

    private function scopeName(string $scopeId): string
    {
        static $memo = [];

        return $memo[$scopeId] ??= (string) (DB::table('jurisdictions')->where('id', $scopeId)->value('name') ?? '?');
    }
}
