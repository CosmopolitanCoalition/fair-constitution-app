<?php

namespace App\Console\Commands;

use App\Services\Autoscale\AutoscaleRunControl;
use Illuminate\Console\Command;

/**
 * autoscale:halt — the CLI half of the Step-3 dashboard's Halt button
 * (UI↔CLI parity). Both doors call AutoscaleRunControl::halt(): stamp
 * halt_requested_at and pump once so the run parks now, not at the next tick.
 * Operator-trusted by construction (it runs in the box shell); the dashboard
 * enforces is_operator on its side. The guard travels with the pair.
 */
class AutoscaleHaltCommand extends Command
{
    protected $signature = 'autoscale:halt';

    protected $description = 'Halt the active full-scale autoscale run (parks it at the next claim boundary)';

    public function handle(AutoscaleRunControl $control): int
    {
        $result = $control->halt();
        if (! ($result['ok'] ?? false)) {
            $this->error($result['error'] ?? 'Halt failed.');

            return self::FAILURE;
        }
        $this->info("Halt requested for run {$result['run_id']} — the pump parked it now.");

        return self::SUCCESS;
    }
}
