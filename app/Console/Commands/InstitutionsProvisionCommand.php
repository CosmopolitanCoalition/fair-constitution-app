<?php

namespace App\Console\Commands;

use App\Services\InstitutionProvisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lane 3 — flesh out every jurisdiction's institutions at planet scale.
 *
 * Run after geodata load + districting. Every jurisdiction holding a
 * legislature comes out of this with an executive, a court, an election board
 * with its system member, and its civic square and halls — so a freshly
 * loaded world is already standing when the first player arrives.
 *
 *   php artisan institutions:provision
 *   php artisan institutions:provision --step=judiciaries
 *   php artisan institutions:provision --dry-run
 *
 * Idempotent and resumable: re-running provisions only what is missing, a
 * kill mid-run loses at most one chunk, and concurrent runs are safe (the
 * partial unique indexes make a loser a silent no-op).
 */
class InstitutionsProvisionCommand extends Command
{
    protected $signature = 'institutions:provision
                            {--step= : Provision one step only (executives|judiciaries|election_boards|board_members|social_spaces)}
                            {--dry-run : Report what is missing without writing}
                            {--no-audit : Skip the audit chain entry (testing only)}';

    protected $description = 'Provision institutions (executive, court, election board, civic spaces) for every jurisdiction with a legislature';

    public function handle(InstitutionProvisionService $service): int
    {
        $steps = $this->option('step')
            ? [$this->option('step')]
            : InstitutionProvisionService::STEPS;

        foreach ($steps as $step) {
            if (! in_array($step, InstitutionProvisionService::STEPS, true)) {
                $this->error("Unknown step [{$step}]. Known: ".implode(', ', InstitutionProvisionService::STEPS));

                return self::FAILURE;
            }
        }

        $this->line('');
        $this->line('  <fg=cyan>Institution provisioning</> — set-based, chunked at '
            .number_format(InstitutionProvisionService::CHUNK).' per committed transaction');

        $legislatures = (int) DB::scalar('SELECT count(*) FROM legislatures WHERE deleted_at IS NULL');
        $binding      = $service->binding();
        $skipped      = $service->skippedUninhabited();

        $this->line('  Jurisdictions with a legislature: <fg=yellow>'.number_format($legislatures).'</>');
        $this->line('  Population binding (founding property): <fg=yellow>'.$binding.'</>');

        if ($binding === 'real') {
            // Report what the zero rule excludes, so "nothing for nobody" reads
            // as a decision rather than as missing data.
            $this->line('  Uninhabited, deliberately skipped: <fg=yellow>'.number_format($skipped).'</>'
                .' <fg=gray>(no people, no parts → no institutions)</>');
        } else {
            $this->line('  <fg=gray>Population imposes nothing — every jurisdiction gets its full set.</>');
        }

        $this->line('');

        if ($this->option('dry-run')) {
            $rows = [];
            foreach ($steps as $step) {
                $rows[] = [$step, number_format($service->pendingTotal($step))];
            }
            $this->table(['Step', 'Missing'], $rows);
            $this->line('  <fg=gray>Dry run — nothing written.</>');

            return self::SUCCESS;
        }

        $started = microtime(true);
        $totals  = [];

        foreach ($steps as $step) {
            $pending = $service->pendingTotal($step);

            if ($pending === 0) {
                $this->line(sprintf('  <fg=green>✓</> %-16s <fg=gray>already complete</>', $step));
                $totals[$step] = 0;

                continue;
            }

            $bar = $this->output->createProgressBar($pending);
            $bar->setFormat("  <fg=blue>▸</> %message:-16s% %bar% %current%/%max% <fg=gray>(%elapsed%)</>");
            $bar->setMessage($step);
            $bar->start();

            $created = $service->provisionStep(
                $step,
                function (string $s, int $done, int $total, int $made) use ($bar): void {
                    $bar->setProgress(min($done, $total));
                },
                audit: ! $this->option('no-audit'),
            );

            $bar->finish();
            $this->line('');
            $this->line(sprintf('    <fg=green>created %s</>', number_format($created)));

            $totals[$step] = $created;
        }

        $elapsed = round(microtime(true) - $started, 1);

        $this->line('');
        $this->table(
            ['Step', 'Created'],
            array_map(static fn ($k, $v) => [$k, number_format($v)], array_keys($totals), $totals),
        );
        $this->line("  <fg=green>Done</> in {$elapsed}s");
        $this->line('');
        $this->line('  <fg=gray>Not created here, by design: committees, departments and boards of</>');
        $this->line('  <fg=gray>governors are acts of self-government (Art. II §9) and arrive by vote;</>');
        $this->line('  <fg=gray>activation state belongs to CLK-06 alone.</>');
        $this->line('');

        return self::SUCCESS;
    }
}
