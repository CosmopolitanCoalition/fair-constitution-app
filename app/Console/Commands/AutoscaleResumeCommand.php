<?php

namespace App\Console\Commands;

use App\Services\Autoscale\AutoscaleRunControl;
use Illuminate\Console\Command;

/**
 * autoscale:resume — the CLI half of the Step-3 dashboard's Resume button
 * (UI↔CLI parity). Both doors call AutoscaleRunControl::resume(): clear
 * halt_requested_at and pump. --requeue-review mirrors the dashboard's "Retry
 * all review items": revive a done run's review/failed/halted items (their
 * stale scope trees dropped) so the pump re-mints fresh root scopes.
 */
class AutoscaleResumeCommand extends Command
{
    protected $signature = 'autoscale:resume {--requeue-review : Revive review/failed/halted items — a done run flips back to mapping}';

    protected $description = 'Resume a halted full-scale autoscale run (optionally requeue review items)';

    public function handle(AutoscaleRunControl $control): int
    {
        $result = $control->resume((bool) $this->option('requeue-review'));
        if (! ($result['ok'] ?? false)) {
            $this->error($result['error'] ?? 'Resume failed.');

            return self::FAILURE;
        }
        $this->info("Resumed run {$result['run_id']} — the pump takes over within a minute.");

        return self::SUCCESS;
    }
}
