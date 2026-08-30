<?php

namespace App\Console\Commands;

use App\Services\DistrictingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * apportion:scan — THE ARITHMETIC CHECKER (THE LEVEL LAW, operator order
 * 2026-08-30). Computes every legislature's full seat ledger straight from
 * the population tree (cube root of the root's own row; per level, each
 * child's own row over the direct children's row sum; giant fixpoint with
 * the lock landing) and compares it against the drawn map. No drawing, no
 * geometry. A map is CLEAN when its net drawn total equals the chamber and
 * every drawn scope's sum equals that scope's ledger entry (pool for a
 * child-bearing scope, full budget for a childless one).
 *
 * ETL paradigm: keyset-chunked by legislature id, resumable via --after,
 * per-chunk progress with measured rate and ETA, one fresh service instance
 * per legislature so memos stay bounded. Reads only.
 */
class ApportionScanCommand extends Command
{
    protected $signature = 'apportion:scan
        {--legislature= : scan one legislature id}
        {--after= : resume after this legislature id (keyset)}
        {--chunk=2000 : legislatures per chunk}
        {--limit=0 : stop after N legislatures (0 = all)}
        {--out=apportion_scan.csv : mismatch CSV in storage/app}';

    protected $description = 'Analytic seat-ledger scan of drawn maps (level law, no drawing)';

    public function handle(): int
    {
        $chunk = max(100, (int) $this->option('chunk'));
        $limit = (int) $this->option('limit');
        $after = (string) ($this->option('after') ?? '');
        $one   = $this->option('legislature');
        $out   = storage_path('app/' . $this->option('out'));
        $fh    = fopen($out, $after === '' && ! $one ? 'w' : 'a');
        if ($after === '' && ! $one) {
            fputcsv($fh, ['legislature_id', 'jurisdiction', 'class', 'chamber', 'drawn_net', 'scope_mismatches', 'detail']);
        }

        $scanned = 0; $noMap = 0; $pending = 0; $classTotal = 0; $classScope = 0; $clean = 0; $problems = 0;
        $t0 = microtime(true);

        while (true) {
            $q = DB::table('legislatures as l')
                ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
                ->whereNull('l.deleted_at')->whereNull('j.deleted_at')
                // THE SCAN DOMAIN (operator, 2026-08-30): only legislatures
                // whose root has children. A leaf legislature has no split,
                // so the level law has nothing to check there.
                ->whereExists(function ($s) {
                    $s->select(DB::raw(1))->from('jurisdictions as c')
                        ->whereColumn('c.parent_id', 'j.id')
                        ->whereNull('c.deleted_at');
                })
                ->orderBy('l.id')
                ->select('l.id', 'l.jurisdiction_id', 'j.name', 'j.population');
            if ($one) {
                $q->where('l.id', $one);
            } elseif ($after !== '') {
                $q->where('l.id', '>', $after);
            }
            $rows = $q->limit($chunk)->get();
            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $r) {
                $after = (string) $r->id;
                $scanned++;

                // The active map (latest non-archived fallback).
                $map = DB::table('legislature_district_maps')
                    ->where('legislature_id', $r->id)->whereNull('deleted_at')
                    ->orderByRaw("(status = 'active') DESC")
                    ->orderByDesc('created_at')
                    ->where('status', '<>', 'archived')
                    ->first(['id', 'status']);
                if ($map === null) {
                    $noMap++;
                    continue;
                }

                $chamber = max(5, (int) round(((int) $r->population) ** (1 / 3)));

                $drawn = DB::table('legislature_districts')
                    ->where('map_id', $map->id)->whereNull('deleted_at')
                    ->selectRaw('COUNT(*) AS n, COALESCE(SUM(seats - COALESCE(bonus_seats,0)),0) AS net')
                    ->first();
                $drawnNet = (int) $drawn->net;

                // An empty map is PENDING (the unprocessed pile), never a
                // mismatch: nothing was drawn, so nothing disagrees.
                if ((int) $drawn->n === 0) {
                    $pending++;
                    if ($limit > 0 && $scanned >= $limit) {
                        break 2;
                    }
                    continue;
                }

                // The ledger walk (fresh instance per legislature: bounded memos).
                $svc = new DistrictingService();
                $ledger = [];          // scope id => expected drawn sum at that scope
                $walkProblems = 0;
                $walk = function (string $scopeId, int $budget) use (&$walk, &$ledger, &$walkProblems, $svc, $r) {
                    $giants  = $svc->giantChildrenForScope($scopeId, (string) $r->id);
                    $lockSum = array_sum($giants);
                    $pool    = $budget - $lockSum;
                    if ($pool < 0) {
                        $walkProblems++;
                    }
                    $kids = DB::table('jurisdictions')
                        ->where('parent_id', $scopeId)->whereNull('deleted_at')->count();
                    $ledger[$scopeId] = $kids > 0 ? max($pool, 0) : $budget;
                    foreach ($giants as $gid => $gb) {
                        $walk((string) $gid, (int) $gb);
                    }
                };
                $walk((string) $r->jurisdiction_id, $chamber);
                $expectedNet = array_sum($ledger);

                // Per-scope drawn sums on the map.
                $scopeSums = DB::table('legislature_districts')
                    ->where('map_id', $map->id)->whereNull('deleted_at')
                    ->whereNotNull('jurisdiction_id')
                    ->groupBy('jurisdiction_id')
                    ->selectRaw('jurisdiction_id, SUM(seats - COALESCE(bonus_seats,0)) AS net')
                    ->pluck('net', 'jurisdiction_id');

                $scopeMism = 0;
                foreach ($ledger as $sid => $exp) {
                    if ($exp > 0 && (int) ($scopeSums[$sid] ?? 0) !== $exp) {
                        $scopeMism++;
                    }
                }
                foreach ($scopeSums as $sid => $net) {
                    if (! array_key_exists((string) $sid, $ledger)) {
                        $scopeMism++;   // districts on a scope outside the lawful walk
                    }
                }

                if ($walkProblems > 0) {
                    $problems++;
                    fputcsv($fh, [$r->id, $r->name, 'walk_problem', $chamber, $drawnNet, $scopeMism, "walk problems: {$walkProblems}"]);
                } elseif ($drawnNet !== $expectedNet) {
                    $classTotal++;
                    fputcsv($fh, [$r->id, $r->name, 'total', $chamber, $drawnNet, $scopeMism, "expected {$expectedNet}"]);
                } elseif ($scopeMism > 0) {
                    $classScope++;
                    fputcsv($fh, [$r->id, $r->name, 'scope', $chamber, $drawnNet, $scopeMism, '']);
                } else {
                    $clean++;
                }

                if ($limit > 0 && $scanned >= $limit) {
                    break 2;
                }
            }

            $dt = microtime(true) - $t0;
            $rate = $scanned / max($dt, 0.001);
            $this->line(sprintf(
                'scanned %d (clean %d, total %d, scope %d, problems %d, pending %d, no-map %d) — %.0f/s — last %s',
                $scanned, $clean, $classTotal, $classScope, $problems, $pending, $noMap, $rate, $after
            ));

            if ($one || $rows->count() < $chunk) {
                break;
            }
        }

        fclose($fh);
        $this->info(sprintf(
            'DONE: %d scanned. CLEAN %d. REPROCESS %d (total %d + scope %d + walk-problem %d). PENDING %d. no-map %d. CSV: %s',
            $scanned, $clean, $classTotal + $classScope + $problems,
            $classTotal, $classScope, $problems, $pending, $noMap, $out
        ));

        return self::SUCCESS;
    }
}
