<?php

namespace App\Console\Commands;

use App\Support\InstanceClass;
use Illuminate\Console\Command;

/**
 * instance:class — show or change this box's instance class (operator act).
 *
 * Operator ruling 2026-09-17 (beta-instance-class = A): a founded box may be
 * flipped to scale_demo (or back) by an operator act that lands on the audit
 * record. Demo mode, its session capture and demo:void-expired read the class
 * (App\Support\DemoMode), so a flip to scale_demo is what arms the hybrid demo
 * on the beta. The change itself lives in InstanceClass::change(), the single
 * owner the operations console shares.
 *
 *   php artisan instance:class                         show the current class
 *   php artisan instance:class scale_demo --yes        flip (asks without --yes)
 *   php artisan instance:class production --yes --reason="conference over"
 */
class InstanceClassCommand extends Command
{
    protected $signature = 'instance:class
        {class? : production | scale_demo (omit to show the current class)}
        {--reason= : a short reason recorded on the audit entry}
        {--yes : do not ask for confirmation}';

    protected $description = 'Show or change the instance class (production | scale_demo) as an operator act on the record';

    public function handle(): int
    {
        $current = InstanceClass::current();
        $target = $this->argument('class');

        if ($target === null) {
            $this->line("instance_class = {$current}");

            return self::SUCCESS;
        }

        if (! in_array($target, [InstanceClass::PRODUCTION, InstanceClass::SCALE_DEMO], true)) {
            $this->error('The class must be production or scale_demo.');

            return self::INVALID;
        }

        if ($target === $current) {
            $this->line("instance_class is already {$current}; nothing to do.");

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm("Change instance_class from {$current} to {$target}? This is recorded on the audit log.")) {
            $this->line('Not changed.');

            return self::SUCCESS;
        }

        $result = InstanceClass::change($target, null, (string) ($this->option('reason') ?? 'console'));

        $this->info(sprintf(
            'instance_class: %s -> %s (audit %s).',
            $result['from'], $result['to'], $result['audited'] ? 'recorded' : 'not available on this store'
        ));
        if ($target === InstanceClass::SCALE_DEMO) {
            $this->line('Demo mode is now armed: guests go through the motions, session writes are purged by demo:void-expired.');
        }

        return self::SUCCESS;
    }
}
