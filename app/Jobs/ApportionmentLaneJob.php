<?php

namespace App\Jobs;

use App\Support\AutoscaleEnumeration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One apportionment-ledger lane (operator order 2026-08-31): drains the
 * world worklist seeded at the ingest tail — biggest population first,
 * SKIP LOCKED claims, one legislature per claim. The adjacency-precompute
 * pattern applied to the seat arithmetic: materialization is paid once
 * per DATASET, and every run copies its stamped trees from the ledger.
 */
class ApportionmentLaneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public function handle(): void
    {
        $token = (string) Str::uuid();
        $done = 0;
        $t0 = time();
        while (AutoscaleEnumeration::processApportionmentClaim($token)) {
            $done++;
            if ($done % 500 === 0) {
                Log::info('Apportionment lane progress', [
                    'computed' => $done, 'elapsed_s' => time() - $t0,
                ]);
            }
            if (time() - $t0 > $this->timeout - 120) {
                // Requeue a fresh lane instead of dying mid-claim.
                self::dispatch();

                return;
            }
        }
        Log::info('Apportionment lane drained', ['computed' => $done, 'elapsed_s' => time() - $t0]);
    }
}
