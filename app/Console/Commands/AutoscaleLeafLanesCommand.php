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
    protected $signature = 'autoscale:leaf-lanes {n : lanes that prefer line-split scopes (0 = one pile)}';

    protected $description = 'Set how many autoscale lanes prefer the line-split pile (composites take the rest; both spill)';

    public function handle(): int
    {
        $n = (string) $this->argument('n');
        if (! ctype_digit($n) || (int) $n > 64) {
            $this->error('Give a whole number 0..64.');

            return self::FAILURE;
        }
        $run = AutoscaleRun::unfinished();
        if ($run === null) {
            $this->error('No active autoscale run.');

            return self::FAILURE;
        }
        $run->forceFill(['leaf_lanes' => (int) $n])->save();
        $this->info((int) $n === 0
            ? 'One pile: every lane follows the walk order.'
            : "Two piles: {$n} lane(s) prefer line-splits, the rest composites; both spill.");

        return self::SUCCESS;
    }
}
