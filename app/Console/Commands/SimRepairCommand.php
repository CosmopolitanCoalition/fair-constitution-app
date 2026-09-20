<?php

namespace App\Console\Commands;

use App\Models\SimRun;
use App\Services\Demo\SimRepairControl;
use Illuminate\Console\Command;

class SimRepairCommand extends Command
{
    protected $signature = 'sim:repair {source? : Completed original run UUID} {--scope=* : Exact pilot jurisdiction UUID, repeatable} {--repair-version=1} {--resume= : Repair run whose enumeration was interrupted} {--apply= : Apply a completed repair plan} {--status= : Read a repair manifest page as JSON} {--after= : Manifest continuation key} {--refresh-plan= : Reclassify an older drained inspection without restarting it} {--recover-noops= : Correct proven no-op receipts for this halted repair run and exact --scope values}';
    protected $description = 'Plan or explicitly apply a bounded, resumable in-place Step 5 repair';

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('enable-election-recovery', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED,
            'Record authorized supplementary elections and unfinished-count recovery on this halted repair run');
    }

    public function handle(SimRepairControl $control): int
    {
        try {
            if ($id = $this->option('enable-election-recovery')) {
                $this->line(json_encode($control->enableElectionRecovery(SimRun::findOrFail($id), $this->option('scope')), JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }
            if ($id = $this->option('refresh-plan')) {
                $this->line(json_encode($control->refreshInventory(SimRun::findOrFail($id)), JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }
            if ($id = $this->option('recover-noops')) {
                $this->line(json_encode(app(\App\Services\Demo\SimRepairReceiptRecovery::class)->recover(SimRun::findOrFail($id), $this->option('scope')), JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }
            if ($id = $this->option('status')) {
                $this->line(json_encode($control->report(SimRun::findOrFail($id), $this->option('after')), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return self::SUCCESS;
            }
            if ($id = $this->option('apply')) {
                $run = SimRun::findOrFail($id); $control->apply($run);
            } else {
                $run = $this->option('resume') ? SimRun::findOrFail($this->option('resume'))
                    : $control->start((string) $this->argument('source'), $this->option('scope'), (int) $this->option('repair-version'));
                $this->info('Repair run '.$run->id.'; enumerating inspection only.');
                $control->enumerate($run);
            }
            $this->call('sim:pump');
            $this->info('Run '.$run->id.'. Planning pauses before changes; --apply='.$run->id.' authorizes repair. Existing sim:halt/sim:resume controls apply.');
            return self::SUCCESS;
        } catch (\Throwable $error) { $this->error($error->getMessage()); return self::FAILURE; }
    }
}
