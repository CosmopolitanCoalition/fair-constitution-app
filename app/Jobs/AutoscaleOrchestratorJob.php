<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RETIRED (pull engine, 2026-07-19) — kept one release as a no-op stub.
 *
 * The self-rescheduling orchestrator tick chain is gone: `autoscale:pump`
 * (scheduler, every minute) + AutoscaleWorkerJob (claim loop) replaced it.
 * Redis still holds serialized payloads of this class from a live deploy
 * (delayed tick successors); without the stub each one would throw
 * class-not-found in Horizon. Delete after the next flattening.
 */
class AutoscaleOrchestratorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 1;

    public function __construct(private readonly string $runId)
    {
        $this->onQueue('autoscale-tick');
    }

    public function handle(): void
    {
        Log::info('AutoscaleOrchestratorJob stub: retired payload discarded (the pump owns the run)', [
            'run_id' => $this->runId,
        ]);
    }
}
