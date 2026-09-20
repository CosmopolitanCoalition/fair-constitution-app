<?php

namespace App\Jobs;

use App\Services\Demo\SimulationStatisticsMaintenance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One scheduled check, never dispatched by simulation lanes. */
class MaintainSimulationStatisticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct()
    {
        $this->onQueue('long-running');
    }

    public function handle(SimulationStatisticsMaintenance $maintenance): void
    {
        $maintenance->check();
    }
}
