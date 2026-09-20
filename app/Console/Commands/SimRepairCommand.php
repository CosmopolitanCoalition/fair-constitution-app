<?php

namespace App\Console\Commands;

use App\Models\SimRun;
use App\Services\Demo\SimRepairControl;
use Illuminate\Console\Command;

class SimRepairCommand extends Command
{
    protected $signature = 'sim:repair {source? : Completed original run UUID} {--scope=* : Exact pilot jurisdiction UUID, repeatable} {--repair-version=1} {--resume= : Repair run whose enumeration was interrupted} {--apply= : Apply a completed repair plan} {--status= : Read a repair manifest page as JSON} {--after= : Manifest continuation key}';
    protected $description = 'Plan or explicitly apply a bounded, resumable in-place Step 5 repair';

    public function handle(SimRepairControl $control): int
    {
        try {
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
