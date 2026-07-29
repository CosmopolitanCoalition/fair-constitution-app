<?php

namespace App\Console\Commands;

use App\Http\Middleware\DevTimeControlsEnabled;
use App\Services\Dev\ScenarioPresetService;
use Illuminate\Console\Command;

/**
 * D5's CLI face. Parity here runs the OTHER way round: every preset's CLI
 * half existed first (it IS the seeder command), so the terminal never
 * lost anything — what it lacked was the MAP. This prints it: preset →
 * command, whether the advisory probe says it can run here, and why not
 * when it can't.
 *
 *   php artisan dev:scenario            # the map + availability
 *   php artisan dev:scenario election   # print the exact command to run
 *
 * Deliberately does NOT execute the seeder — in a terminal you run the
 * seeder itself, with its own flags and its own live output. Queueing is
 * the flyout's affordance for people without a shell.
 */
class DevScenarioCommand extends Command
{
    protected $signature = 'dev:scenario {preset? : Print the exact command one preset maps to}';

    protected $description = 'Playtest control: the scenario-preset map — which named situations can be seeded here, and by what command';

    public function handle(ScenarioPresetService $scenarios): int
    {
        if ($reason = DevTimeControlsEnabled::refusalReason()) {
            $this->error($reason);

            return self::FAILURE;
        }

        $presets = ScenarioPresetService::presets();
        $picked = $this->argument('preset');

        if ($picked !== null) {
            if (! isset($presets[$picked])) {
                $this->error('Unknown preset. Known: '.implode(', ', array_keys($presets)).'.');

                return self::FAILURE;
            }

            $def = $presets[$picked];
            [$ok, $whyNot] = $scenarios->probe($picked);

            $this->line('  '.$def['label']);
            $this->info('  php artisan '.$def['command'].$this->argString($def['args']));
            $ok
                ? $this->line('  The advisory probe says this can run here (the seeder has the final word).')
                : $this->warn('  '.$whyNot);

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($presets as $id => $def) {
            [$ok, $whyNot] = $scenarios->probe($id);
            $run = $scenarios->runState($id);

            $rows[] = [
                $id,
                'php artisan '.$def['command'].$this->argString($def['args']),
                $ok ? 'ready' : 'blocked',
                $whyNot ?? ($run !== null ? "last run: {$run['status']}" : '—'),
            ];
        }

        $this->table(['preset', 'command', 'probe', 'note'], $rows);

        $this->line('  Mockup flags with NO seeder (honest absence): '.implode(', ', array_keys(ScenarioPresetService::unbacked())).'.');

        return self::SUCCESS;
    }

    private function argString(array $args): string
    {
        $parts = '';

        foreach ($args as $key => $value) {
            $parts .= $value === true ? " {$key}" : (str_starts_with((string) $key, '--') ? " {$key}={$value}" : " {$value}");
        }

        return $parts;
    }
}
