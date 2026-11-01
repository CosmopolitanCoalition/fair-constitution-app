<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Nightly run of residents:recount on the low-priority prewarm lane. A job rather than a
 * scheduler command: the recount takes minutes, and the scheduler must keep ticking every
 * minute for the pumps and the clock sweep.
 */
class RecountResidentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct()
    {
        $this->onQueue('prewarm');
    }

    public function handle(): void
    {
        Artisan::call('residents:recount');
    }
}
