<?php

namespace App\Console\Commands;

use App\Services\Autoscale\AutoscaleRunControl;
use Illuminate\Console\Command;

/**
 * CLI twin of the Step-3 Auto-kill checkbox (UI/CLI parity, standing rule).
 * Sets the run's auto-kill limit in minutes; the pump kills any scope or
 * batch claim older than the limit every minute. --off clears it. Trivial
 * (singles) claims are never auto-killed.
 */
class AutoscaleAutoKillCommand extends Command
{
    protected $signature = 'autoscale:auto-kill {minutes? : kill claims older than this many minutes (1-1440)} {--off : clear the limit}';

    protected $description = 'Set or clear the autoscale auto-kill limit for scope claims (minutes)';

    public function handle(AutoscaleRunControl $control): int
    {
        $minutes = null;
        if (! $this->option('off')) {
            $raw = $this->argument('minutes');
            if ($raw === null || ! ctype_digit((string) $raw) || (int) $raw < 1 || (int) $raw > 1440) {
                $this->error('Give minutes in 1..1440, or --off to clear.');

                return self::FAILURE;
            }
            $minutes = (int) $raw;
        }

        $result = $control->setAutoKillMinutes($minutes);
        if (! ($result['ok'] ?? false)) {
            $this->error($result['error'] ?? 'Auto-kill update failed.');

            return self::FAILURE;
        }
        $this->info($minutes === null
            ? 'Auto-kill cleared.'
            : "Auto-kill set: claims older than {$minutes} min are killed by the pump.");

        return self::SUCCESS;
    }
}
