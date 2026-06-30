<?php

namespace App\Jobs\Federation;

use App\Models\ClusterMembership;
use App\Services\Mirror\MirrorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the long mirror seed + drain OFF the request thread (Phase G) so the
 * operator's browser polls live progress (SyncProgressService) instead of
 * holding a multi-hour HTTP request open. The console admit step already pinned
 * the host and opened the SYNCING membership synchronously — so a bad join key
 * fails fast, in-band — and this job only does the resumable, idempotent sync
 * tail (seed → import → drain → mark live).
 *
 * Routed to the `long-running` Horizon supervisor (timeout=0): a foundation seed
 * is a multi-GB transfer, exactly the "import worker" that supervisor exists for —
 * supervisor-1's 60 s ceiling would SIGTERM it. tries=1: the sync is resumable by
 * design (seeded_at short-circuits the seed; the cold cursor resumes the drain),
 * so a re-trigger continues rather than restarts; we don't auto-retry mid-drain.
 */
class ClusterJoinJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public readonly string $membershipId)
    {
        $this->onConnection('redis');
        $this->onQueue('long-running');
    }

    public function handle(MirrorService $mirror): void
    {
        $membership = ClusterMembership::query()->find($this->membershipId);

        // The operator may have left the cluster (or the request was rejected)
        // between dispatch and pickup — nothing to sync.
        if ($membership === null || ! $membership->isActive()) {
            return;
        }

        $mirror->syncMembership($membership);
    }
}
