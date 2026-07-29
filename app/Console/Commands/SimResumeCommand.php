<?php

namespace App\Console\Commands;

use App\Services\Demo\SimRunControl;
use Illuminate\Console\Command;

/**
 * Resume a halted simulated-world run.
 *
 * The CLI half of the console's Resume control — both call the SAME
 * `SimRunControl::resume()`, clearing the halt flag the pump honours. Note this
 * is NOT `sim:start --resume`: that re-enumerates a crashed run's worklist,
 * while this un-parks a deliberately halted one. Two different acts; this is the
 * one the operator reaches for after a Halt.
 */
class SimResumeCommand extends Command
{
    protected $signature = 'sim:resume';

    protected $description = 'Resume a halted simulated-world populate run (clear the halt flag; the pump re-seeds workers)';

    public function handle(SimRunControl $control): int
    {
        $result = $control->resume('cli');

        if (! $result['ok']) {
            $this->error($result['reason']);

            return self::FAILURE;
        }

        $this->info('run resumed — the pump flips it back to running and re-seeds workers within the minute');

        return self::SUCCESS;
    }
}
