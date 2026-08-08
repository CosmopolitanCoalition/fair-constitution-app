<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * The per-jurisdiction Simulate control's worker (operator, 2026-08-08 — the
 * narrow co-test: activate a jurisdiction, draw its map, then simulate THAT
 * jurisdiction). Queued because enumeration is chunked bulk work that must
 * never run inline on an HTTP request. sim:start's own guards hold: synthetic
 * data only on a sandbox world, and one unfinished run holds the engine.
 */
class SimulateJurisdictionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 0;

    public function __construct(public readonly string $jurisdictionSlug)
    {
    }

    public function handle(): void
    {
        $exit = Artisan::call('sim:start', ['--jurisdiction' => $this->jurisdictionSlug]);
        Log::info(sprintf(
            'SimulateJurisdictionJob %s: sim:start exited %d — %s',
            $this->jurisdictionSlug, $exit, trim(substr(Artisan::output(), -300)),
        ));
    }
}
