<?php

namespace App\Jobs\Legislature;

use App\Http\Controllers\LegislatureController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Builds the exact district coloring of one (legislature, map) in the background.
 *
 * The coloring needs a bounding-box adjacency join over every member jurisdiction. On a
 * host with a slow disk that reads large geometries and can take many minutes, so a page
 * never waits for it: a cold read answers with placeholder colors and dispatches this job.
 * The result is stored in both cache tiers (GeojsonDurableCache), so it is built once per
 * legislature map and then survives restarts.
 *
 * One build at a time per (legislature, map): a burst of page loads on a cold map queues a
 * single job. The lane is the low-priority prewarm lane, so it never takes the slot a
 * person's page needs.
 */
class BuildLegislatureColorsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    /** The unique lock outlives a normal build and clears itself if the job dies. */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $legislatureId,
        public readonly ?string $mapId,
    ) {
        $this->onQueue('prewarm');
    }

    public function uniqueId(): string
    {
        return $this->legislatureId.':'.($this->mapId ?? 'null');
    }

    public function handle(): void
    {
        app(LegislatureController::class)->buildColorMapNow($this->legislatureId, $this->mapId);
    }
}
