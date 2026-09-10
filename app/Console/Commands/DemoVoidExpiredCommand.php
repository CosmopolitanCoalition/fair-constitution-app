<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoSessionService;
use App\Support\DemoMode;
use Illuminate\Console\Command;

/**
 * demo:void-expired — the scheduled half of the compensating purge (DemoMode
 * ruling C). A demo session whose Laravel session has expired is voided the
 * same way a logout voids it. Inert off a scale_demo box.
 *
 * demo:capture-install lives beside it: re-run the trigger installer so a
 * table added after the migration is captured too.
 */
class DemoVoidExpiredCommand extends Command
{
    protected $signature = 'demo:void-expired {--install : (re)install the row-capture trigger on every capturable table instead}';

    protected $description = 'Void demo sessions whose Laravel session has expired (scale_demo boxes only)';

    public function handle(DemoSessionService $demo): int
    {
        if ($this->option('install')) {
            $r = $demo->installCapture();
            $this->line("capture trigger installed on {$r['installed']} tables; {$r['excluded']} excluded; "
                . count($r['skipped_no_pk']).' skipped (no primary key)');

            return self::SUCCESS;
        }

        if (! DemoMode::active()) {
            $this->line('not a scale_demo box; nothing to do');

            return self::SUCCESS;
        }

        $n = $demo->voidExpired();
        $this->line("voided {$n} expired demo session(s)");

        return self::SUCCESS;
    }
}
