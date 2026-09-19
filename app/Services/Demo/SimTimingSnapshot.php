<?php

namespace App\Services\Demo;

use App\Models\SimRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Recent deltas of the existing batched counters; no additional worker writes. */
class SimTimingSnapshot
{
    public const SAMPLE_SECONDS = 10;
    public const WINDOW_SECONDS = 60;

    public function rows(SimRun $run): array
    {
        $key = 'sim:timings:v1:'.$run->id.':'.$run->phase.':'.$run->status;
        $cached = Cache::get($key);
        $now = now()->timestamp;
        if ($cached && $now - $cached['sampled_ts'] < self::SAMPLE_SECONDS) {
            return $cached['rows'];
        }

        $lock = Cache::lock($key.':refresh', self::WINDOW_SECONDS);
        if (! $lock->get()) {
            return $cached['rows'] ?? [];
        }
        try {
            $cached = Cache::get($key);
            if ($cached && $now - $cached['sampled_ts'] < self::SAMPLE_SECONDS) {
                return $cached['rows'];
            }
            $counters = DB::table('sim_timings')->where('run_id', (string) $run->id)
                ->get(['part', 'count', 'total_us', 'max_us'])->keyBy('part')
                ->map(fn ($row) => array_map('intval', [
                    'count' => $row->count, 'total_us' => $row->total_us, 'max_us' => $row->max_us,
                ]))->all();
            $now = now()->timestamp;
            // At most seven ten-second samples. A long observation gap starts
            // a fresh baseline; it must not masquerade as a recent window.
            $history = array_values(array_filter($cached['history'] ?? [],
                fn ($sample) => $sample['ts'] >= $now - self::WINDOW_SECONDS));
            $baseline = $history[0] ?? null;
            $window = $baseline ? $now - $baseline['ts'] : null;
            $rows = [];
            foreach ($counters as $part => $current) {
                $old = $baseline['counters'][$part] ?? ['count' => 0, 'total_us' => 0];
                $count = $current['count'] - $old['count'];
                $us = $current['total_us'] - $old['total_us'];
                $valid = $window > 0 && $count >= 0 && $us >= 0;
                $rows[] = [
                    'part' => $part, 'count' => $current['count'],
                    'avg_ms' => $current['count'] > 0 ? round($current['total_us'] / $current['count'] / 1000, 2) : 0.0,
                    'max_ms' => round($current['max_us'] / 1000, 2),
                    'total_s' => round($current['total_us'] / 1_000_000, 1),
                    'recent_count' => $valid ? $count : null,
                    'recent_total_us' => $valid ? $us : null,
                    'recent_avg_ms' => $valid && $count > 0 ? round($us / $count / 1000, 2) : null,
                    'window_seconds' => $valid ? $window : null,
                    'sampled_at' => now()->toIso8601String(),
                ];
            }
            $rows = collect($rows)->sortByDesc('total_s')->values()->all();
            $history[] = ['ts' => $now, 'counters' => $counters];
            Cache::put($key, ['rows' => $rows, 'history' => $history, 'sampled_ts' => $now], 2 * self::WINDOW_SECONDS);

            return $rows;
        } finally {
            $lock->release();
        }
    }
}
