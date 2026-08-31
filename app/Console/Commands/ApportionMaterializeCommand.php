<?php

namespace App\Console\Commands;

use App\Jobs\ApportionmentLaneJob;
use App\Support\AutoscaleEnumeration;
use App\Support\HostCapacity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Build the world apportionment ledger (operator order 2026-08-31): the
 * ingest-tail pass, invokable by hand on a box whose geodata is already
 * ingested. Seeds the worklist (missing + stale rows) and dispatches
 * lanes; --inline drains in this process with visible progress instead.
 */
class ApportionMaterializeCommand extends Command
{
    protected $signature = 'apportion:materialize-world {--inline : drain in this process with progress}';

    protected $description = 'Compute the world apportionment ledger: heads, stamped scope trees, walk order, pre-draw gate verdicts';

    public function handle(): int
    {
        $seeded = AutoscaleEnumeration::seedApportionmentWorklist();
        $pending = (int) DB::table('apportionment_ledger')->where('status', 'pending')->count();
        $this->info("Worklist seeded: {$seeded} new/stale; {$pending} pending total.");

        if (! $this->option('inline')) {
            $lanes = max(2, min(HostCapacity::autoscaleWorkers(), max(1, $pending)));
            for ($i = 0; $i < $lanes; $i++) {
                ApportionmentLaneJob::dispatch();
            }
            $this->info("Dispatched {$lanes} ledger lanes to the queue.");

            return self::SUCCESS;
        }

        $token = (string) \Illuminate\Support\Str::uuid();
        $done = 0;
        $t0 = time();
        while (AutoscaleEnumeration::processApportionmentClaim($token)) {
            $done++;
            if ($done % 250 === 0) {
                $el = max(time() - $t0, 1);
                $rate = $done / $el;
                $left = (int) (max($pending - $done, 0) / max($rate, 0.01));
                $this->info(sprintf('  %d/%d computed · %.1f/s · ~%dm %ds left', $done, $pending, $rate, intdiv($left, 60), $left % 60));
            }
        }
        $refused = (int) DB::table('apportionment_ledger')->whereNotNull('gate_reason')->where('status', 'done')->count();
        $failed  = (int) DB::table('apportionment_ledger')->where('status', 'failed')->count();
        $this->info("Drained: {$done} computed in " . (time() - $t0) . "s. Gate refusals: {$refused}. Compute failures: {$failed}.");

        return self::SUCCESS;
    }
}
