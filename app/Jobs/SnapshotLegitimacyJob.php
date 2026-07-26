<?php

namespace App\Jobs;

use App\Services\Cluster\LeaderProbe;
use App\Services\LegitimacyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase I — the nightly REACH snapshot.
 *
 * An ordinary scheduled job, deliberately OUTSIDE the CLK registry: reach is a
 * gauge, not a constitutional clock. Nothing waits on it and nothing fires from
 * it; if a night is missed the gauge is simply a day stale.
 *
 * ── Two orthogonal gates, and they are not the same thing ────────────────
 *
 * 1. `onOneServer()` + `LeaderProbe::isPrimary()` — the HA axis. The scheduler
 *    runs on every app node, so onOneServer elects one tick-leader per cluster,
 *    and the probe refuses to write from a demoted replica (which is read-only
 *    mid-failover). This answers "run once, on a node that can write".
 *
 * 2. `authoritative_server_id IS NULL`, applied per jurisdiction inside
 *    LegitimacyService::snapshotAll() — the CI-6 axis. This answers "for which
 *    PLACES may we write at all".
 *
 * Conflating them is a real and tempting error: a mirror runs its own
 * scheduler and wins its own leader probe, so gate 1 alone would have every
 * mirror writing snapshots for jurisdictions it does not own. Authority is not
 * leadership — the cardinal Phase-G invariant.
 *
 * 00:40 is chosen to sit clear of the existing nightly stagger
 * (00:10 approval standings, 00:20 department reports, 00:30 social structure).
 */
class SnapshotLegitimacyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly ?string $asOfDate = null) {}

    public function handle(LegitimacyService $legitimacy, LeaderProbe $leader): void
    {
        // HA gate: a demoted replica is read-only. Skip cleanly rather than
        // erroring; the next night runs on the promoted primary.
        if (! $leader->isPrimary()) {
            return;
        }

        $legitimacy->snapshotAll($this->asOfDate);
    }
}
