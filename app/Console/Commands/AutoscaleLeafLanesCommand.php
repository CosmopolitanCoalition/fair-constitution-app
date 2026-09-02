<?php

namespace App\Console\Commands;

use App\Models\AutoscaleRun;
use Illuminate\Console\Command;

/**
 * TWO PILES BY CLASS (operator order 2026-09-02): set how many lanes prefer
 * the line-split pile. 0 = one pile in walk order (the default). N >= the
 * pool = every lane on leaves. Read by every lane at its next claim; no
 * restart. The order inside each pile is unchanged.
 */
class AutoscaleLeafLanesCommand extends Command
{
    protected $signature = 'autoscale:leaf-lanes {n : lanes that prefer line-split scopes: a count (6), a share of the derived pool (50%), or 0 = one pile}';

    protected $description = 'Set how many autoscale lanes prefer the line-split pile (composites take the rest; both spill)';

    public function handle(): int
    {
        $raw = trim((string) $this->argument('n'));
        $isPct = str_ends_with($raw, '%');
        $n = rtrim($raw, '%');
        if (! ctype_digit($n) || ($isPct && (int) $n > 100) || (! $isPct && (int) $n > 512)) {
            $this->error('Give a count 0..512, or a share 1%..100%.');

            return self::FAILURE;
        }
        $run = AutoscaleRun::unfinished();
        if ($run === null) {
            $this->error('No active autoscale run.');

            return self::FAILURE;
        }
        if ($isPct) {
            $run->forceFill(['leaf_lanes_pct' => (int) $n, 'leaf_lanes' => null])->save();
            $pool = \App\Support\HostCapacity::autoscaleWorkers();
            $this->info((int) $n === 0
                ? 'One pile: every lane follows the walk order.'
                : sprintf('Two piles: %d%% of the pool prefers line-splits (%d of %d lanes on this host), the rest composites; both spill.', (int) $n, max(1, (int) round($pool * (int) $n / 100)), $pool));
        } else {
            $run->forceFill(['leaf_lanes' => (int) $n, 'leaf_lanes_pct' => null])->save();
            $this->info((int) $n === 0
                ? 'One pile: every lane follows the walk order.'
                : "Two piles: {$n} lane(s) prefer line-splits, the rest composites; both spill.");
        }

        return self::SUCCESS;
    }
}
