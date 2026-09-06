<?php

namespace App\Console\Commands;

use App\Services\Provision\ProvisionRunControl;
use Illuminate\Console\Command;

/**
 * provision:control — the CLI door to the Step 4 run (UI↔CLI parity, Wave 6):
 *
 *   provision:control start
 *   provision:control halt
 *   provision:control resume [--requeue-review]
 *   provision:control revert [--shells] [--force]
 *   provision:control status
 *
 * Same owner as the wizard endpoints (ProvisionRunControl), so the two doors
 * cannot drift.
 */
class ProvisionControlCommand extends Command
{
    protected $signature = 'provision:control {action : start|halt|resume|revert|status} {--requeue-review} {--shells} {--force}';

    protected $description = 'Start, halt, resume, roll back or report the Step 4 institution run';

    public function handle(ProvisionRunControl $control): int
    {
        $action = (string) $this->argument('action');

        $result = match ($action) {
            'start'  => $control->start(),
            'halt'   => $control->halt(),
            'resume' => $control->resume((bool) $this->option('requeue-review')),
            'revert' => $control->revert((bool) $this->option('shells'), (bool) $this->option('force')),
            'status' => $this->status($control),
            default  => ['ok' => false, 'error' => "Unknown action [{$action}]."],
        };

        if (! ($result['ok'] ?? false)) {
            $this->error((string) ($result['error'] ?? 'refused'));

            return self::FAILURE;
        }

        foreach ($result as $k => $v) {
            if ($k === 'ok') {
                continue;
            }
            $this->line(sprintf('%s: %s', $k, is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v)));
        }

        return self::SUCCESS;
    }

    private function status(ProvisionRunControl $control): array
    {
        $run = $control->latestRun();
        if ($run === null) {
            return ['ok' => true, 'run' => null];
        }
        $c = $control->refreshCounters($run);

        return [
            'ok'      => true,
            'run_id'  => (string) $run->id,
            'status'  => $run->status,
            'started' => $run->started_at?->toIso8601String(),
            'ledger'  => (array) $c,
        ];
    }
}
