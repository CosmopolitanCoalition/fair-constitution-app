<?php

namespace App\Console\Commands;

use App\Services\Demo\SimRunControl;
use Illuminate\Console\Command;

/**
 * Roll back the simulated-world run's engine bookkeeping.
 *
 * The CLI half of the console's Roll-back control — both call the SAME
 * `SimRunControl::revert()`, so the synthetic-data guard, the live-run refusal
 * and the escape-hatch seize travel with the pair (ruling 10, UI↔CLI parity).
 *
 * This clears the run's worklist and leases so a fresh `sim:start`
 * re-enumerates cleanly. It does NOT tear down the world the run produced
 * (cohorts, people, seats, civic records) — that cascade is an operator
 * decision (rubric sim-revert-scope), not this command's default.
 */
class SimRevertCommand extends Command
{
    protected $signature = 'sim:revert {--force : Seize a live run — cull its lanes and clear it anyway}';

    protected $description = 'Roll back the sim run bookkeeping (worklist + leases) so a fresh run can re-enumerate';

    public function handle(SimRunControl $control): int
    {
        $result = $control->revert((bool) $this->option('force'));

        if (! $result['ok']) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        foreach ($result['deleted'] as $table => $count) {
            $this->line("  {$table}: {$count}");
        }

        $this->info('run cleared — a fresh sim:start re-enumerates; the world it produced was left in place');

        return self::SUCCESS;
    }
}
