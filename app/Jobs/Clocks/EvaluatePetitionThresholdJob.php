<?php

namespace App\Jobs\Clocks;

use App\Models\Petition;
use App\Services\PetitionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * CLK-17 (C-10) — the petition-threshold SAFETY-NET sweep (same pattern
 * as CLK-05/CLK-06): the signature-insert path is the event-driven
 * primary check; this sweep catches anything it missed (e.g. a threshold
 * lowered by amendment never lowers a SNAPSHOT, but crash-recovery and
 * dev seeding paths land here). Dispatched by EvaluateClocksJob every
 * sweep; needs no armed deadline — petitions watch a quantity, not a
 * date (their CLK-17 timers arm with fires_at NULL as the observable
 * registry record).
 */
class EvaluatePetitionThresholdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $timerId = null,
    ) {
    }

    public function handle(PetitionService $petitions): void
    {
        Petition::query()
            ->where('status', Petition::STATUS_GATHERING)
            ->orderBy('created_at')
            ->each(function (Petition $petition) use ($petitions) {
                $petitions->evaluateThreshold($petition);
            });
    }
}
