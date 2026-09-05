<?php

namespace App\Console\Commands;

use App\Models\AutoscaleRun;
use App\Services\Autoscale\MapQualityStats;
use Illuminate\Console\Command;

/**
 * autoscale:quality-stats — compute (or recompute) the planet-wide map
 * quality statistics of the newest run and cache them on the run row
 * (operator order 2026-09-05). The CLI twin of the pump's done-flip job.
 */
class AutoscaleQualityStatsCommand extends Command
{
    protected $signature = 'autoscale:quality-stats {--run= : run id (default: the newest run)}';

    protected $description = 'Compute and cache the map quality statistics of the newest autoscale run';

    public function handle(MapQualityStats $stats): int
    {
        $run = $this->option('run')
            ? AutoscaleRun::query()->find($this->option('run'))
            : AutoscaleRun::query()->orderByDesc('created_at')->first();
        if ($run === null) {
            $this->error('No autoscale run found.');

            return self::FAILURE;
        }
        $this->info("Computing map quality statistics for run {$run->id} ({$run->status}) …");
        $result = $stats->refresh($run, fn (string $msg) => $this->line('  ' . $msg));
        $a = $result['type_a'];
        $b = $result['type_b'];
        $this->info(sprintf(
            'Done in %ss — Type A: %s maps, %s districts, %s non-contiguous, mean hull %.3f, avg deviation %.2f%%; Type B: %s groupings, %s panels, spread>1 %s, spread breaks %s.',
            $result['seconds'], number_format($a['maps']), number_format($a['districts']),
            number_format($a['contiguity']['non_contiguous_count']), $a['compactness']['mean'], $a['equality']['avg_pct'],
            number_format($b['groupings']), number_format($b['panels']), number_format($b['diversity']['spread_over']),
            number_format($b['contiguity']['spread_count'])
        ));

        return self::SUCCESS;
    }
}
