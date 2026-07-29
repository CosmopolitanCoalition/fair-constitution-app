<?php

namespace App\Console\Commands;

use App\Services\Demo\SimRunControl;
use Illuminate\Console\Command;

/**
 * Halt the active simulated-world run.
 *
 * The CLI half of the console's Halt control — both call the SAME
 * `SimRunControl::halt()`, so the synthetic-data guard, the "is there a run"
 * check and the audit mark travel with the pair. There is no separate halt
 * logic here to drift from the button's.
 */
class SimHaltCommand extends Command
{
    protected $signature = 'sim:halt';

    protected $description = 'Halt the active simulated-world populate run (workers stop at their next claim boundary)';

    public function handle(SimRunControl $control): int
    {
        $result = $control->halt('cli');

        if (! $result['ok']) {
            $this->error($result['reason']);

            return self::FAILURE;
        }

        $this->info('run halt requested — the pump parks workers within the minute; resume picks up where it stopped');

        return self::SUCCESS;
    }
}
