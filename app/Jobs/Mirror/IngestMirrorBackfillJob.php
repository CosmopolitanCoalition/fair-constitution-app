<?php

namespace App\Jobs\Mirror;

use App\Models\ClusterMembership;
use App\Models\FederationPeer;
use App\Services\Mirror\MirrorBackfillService;
use App\Services\Mirror\MirrorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase G (G2) — drain a mirror's backfill asynchronously. Dispatched right after
 * adoption so a fresh mirror catches up without blocking the join; the CLK-20
 * heartbeat also self-drains any open cold cursor, so this job is an accelerator,
 * not the only path. When the drain catches up, the membership goes live and the
 * instance flips into read-only-mirror mode.
 */
class IngestMirrorBackfillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $hostPeerId,
        public readonly string $membershipId,
        public readonly string $hostServerId,
    ) {}

    public function handle(MirrorBackfillService $backfill, MirrorService $mirror): void
    {
        $host = FederationPeer::query()->find($this->hostPeerId);
        $membership = ClusterMembership::query()->find($this->membershipId);

        if ($host === null || $membership === null) {
            return;
        }

        $cursor = $backfill->drain($host, $membership);

        if ($cursor->status === \App\Models\SyncCursor::STATUS_COMPLETE
            && $membership->state === ClusterMembership::STATE_SYNCING) {
            $mirror->markMirrorLive($membership, $this->hostServerId);
        }
    }
}
