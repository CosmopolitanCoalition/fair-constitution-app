<?php

namespace App\Services\Mirror;

use App\Models\ClusterMembership;
use App\Models\FederationPeer;
use App\Models\SyncCursor;
use App\Services\Federation\ColdSyncService;

/**
 * Mirror backfill (Phase G, G2). A thin orchestration over the cold-sync gate:
 * a fresh mirror pulls the host's full public corpus in bounded, resumable,
 * signed PAGES (never one multi-MB body — the live-demo body-size fix). The
 * heavy lifting — paging, the cross-page continuity guard, idempotent re-runs,
 * mid-drain resume — is ColdSyncService's; this layer adds the membership-level
 * progress bookkeeping a mirror UI reads.
 */
class MirrorBackfillService
{
    public function __construct(private readonly ColdSyncService $cold) {}

    /**
     * Drain a host's corpus into local public_records, up to $maxPages
     * (0 = until caught up or aborted). Mirrors the cursor's progress onto the
     * mirror membership when one is supplied. Idempotent + resumable.
     */
    public function drain(FederationPeer $host, ?ClusterMembership $membership = null, int $maxPages = 0): SyncCursor
    {
        $cursor = $this->cold->pull($host, $maxPages);

        if ($membership !== null) {
            $membership->forceFill([
                'backfill_cursor_seq' => (int) $cursor->next_from_seq,
                'backfill_target_seq' => (int) ($host->refresh()->peer_head_seq ?? 0),
                'backfilled_at' => $cursor->status === SyncCursor::STATUS_COMPLETE ? now() : null,
            ])->save();
        }

        return $cursor;
    }
}
