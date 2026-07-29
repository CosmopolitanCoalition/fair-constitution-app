<?php

namespace App\Jobs;

use App\Services\Cluster\LeaderProbe;
use App\Services\WorldStatsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;

/**
 * The nightly world rollup that feeds the Atlas (ATLAS_DESIGN.md §3).
 *
 * Sibling of SnapshotLegitimacyJob and deliberately the same shape: one pass a
 * night, `$tries = 1`, outside the CLK registry. The Atlas is a gauge, not a
 * constitutional clock — nothing waits on this and nothing fires from it, so a
 * missed night leaves the world a day stale and that is all. Retrying a rollup
 * would buy nothing and could double an audit entry.
 *
 * ⚑ TWO ORTHOGONAL GATES, AND THEY ARE NOT THE SAME THING:
 *
 *  1. THE HA AXIS — `onOneServer()` on the schedule plus `LeaderProbe::isPrimary()`
 *     here: "run once, on a node that can write". A demoted replica is
 *     read-only, so it skips cleanly rather than erroring.
 *  2. THE CI-6 AXIS — `authoritative_server_id IS NULL`, applied per
 *     jurisdiction inside WorldStatsService: "for which PLACES may we count at
 *     all". A mirror runs its own scheduler and wins its own leader probe, so
 *     gate 1 alone would have every mirror publishing world totals for places
 *     it does not own. AUTHORITY IS NOT LEADERSHIP — the cardinal Phase-G
 *     invariant, and the reason both gates exist.
 *
 * The table is checked before the pass so a box that has not migrated yet skips
 * instead of failing a nightly run: the Atlas is honest without a rollup (every
 * figure renders as a gap), so a missing table is a no-op, not an incident.
 */
class SnapshotWorldStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly ?string $asOfDate = null) {}

    public function handle(WorldStatsService $stats, LeaderProbe $leader): void
    {
        // Gate 1, the HA axis: a demoted replica cannot write.
        if (! $leader->isPrimary()) {
            return;
        }

        if (! Schema::hasTable('world_stats')) {
            return;
        }

        // Gate 2, the CI-6 axis, lives inside the service — per jurisdiction.
        $stats->snapshot($this->asOfDate);
    }
}
