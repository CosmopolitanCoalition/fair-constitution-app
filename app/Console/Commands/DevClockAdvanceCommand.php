<?php

namespace App\Console\Commands;

use App\Http\Middleware\DevTimeControlsEnabled;
use App\Services\Dev\DevClockService;
use Illuminate\Console\Command;

/**
 * P2 — advance the world (DEV_TIME_AND_ROLE_CONTROLS.md §3).
 *
 * Pulls every registered deadline back N days so scheduled things become due,
 * then lets the engine fire them through its ordinary path.
 *
 *   php artisan dev:clock-advance --days=30            # dry run — shows, moves nothing
 *   php artisan dev:clock-advance --days=30 --apply    # actually advance
 *
 * THE DRY RUN IS THE DEFAULT, deliberately. A ten-year advance on a founded
 * world fires a great deal at once, and the operator must be able to see what
 * he is about to trigger before he triggers it. `--apply` is the deliberate
 * second step, not a flag you forget to leave off.
 */
class DevClockAdvanceCommand extends Command
{
    protected $signature = 'dev:clock-advance
                            {--days= : How many days to bring the world forward}
                            {--apply : Actually move the deadlines (default is a dry run)}';

    protected $description = 'Playtest control: bring scheduled deadlines forward so they become due';

    public function handle(DevClockService $clock): int
    {
        if ($reason = DevTimeControlsEnabled::refusalReason()) {
            $this->error($reason);

            return self::FAILURE;
        }

        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('Say how far: --days=N');

            return self::FAILURE;
        }

        try {
            $plan = $clock->dryRun($days);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->line("  <options=bold>Advancing the world by {$days} day(s)</> — what that would reach:");
        $this->line('');

        if ($plan['timers'] === []) {
            $this->line('  <fg=gray>No timer comes due in that window.</>');
        } else {
            $this->table(
                ['clock', 'place', 'timers due', 'earliest'],
                array_map(static fn (array $t): array => [
                    $t['clock_id'],
                    $t['jurisdiction_name'] ?? '<all>',
                    number_format($t['due']),
                    $t['earliest'],
                ], $plan['timers']),
            );
            $this->line("  <options=bold>{$plan['total_timers']}</> timer(s) would fire.");
        }

        $this->line('');
        $this->line('  Deadlines that would move:');

        foreach ($plan['columns'] as $c) {
            $rows = number_format($c['rows']);
            $tone = $c['rows'] > 0 ? 'yellow' : 'gray';
            $this->line("    <fg={$tone}>{$c['table']}.{$c['column']}</>  {$rows} row(s)  <fg=gray>{$c['why']}</>");
        }

        $this->line('');

        if (! $this->option('apply')) {
            $this->line('  <fg=cyan>Dry run — nothing moved.</> Re-run with <options=bold>--apply</> to advance.');

            return self::SUCCESS;
        }

        $this->line('  <options=bold>Applying…</>');
        $this->line('');

        $last = null;
        $result = $clock->advance($days, function (string $label, int $done, int $total) use (&$last): void {
            // One line per column, rewritten in place — the ETL rule's
            // per-chunk visibility without a wall of scrollback.
            if ($label !== $last) {
                $this->line('');
                $last = $label;
            }
            $this->output->write(sprintf("\r    %-46s %s/%s", $label, number_format($done), number_format($total)));
        });

        $this->line('');
        $this->line('');
        $this->info(sprintf(
            '  Advanced %d day(s). %d timer(s) fired, %d refused or errored.',
            $result['days'],
            $result['fired'],
            $result['failed'],
        ));
        $this->line('  <fg=gray>Recorded on the audit chain as a dev control — a played timeline stays');
        $this->line('  distinguishable from a lived one.</>');

        return self::SUCCESS;
    }
}
