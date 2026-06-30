<?php

namespace App\Console\Commands;

use App\Jobs\Federation\ClusterJoinJob;
use App\Models\ClusterMembership;
use App\Services\Mirror\MirrorService;
use Illuminate\Console\Command;

/**
 * federation:resume-join — resume an in-progress mirror join (seed + drain) for the
 * active mirror membership (Phase G). The seed import + audit drain of a large corpus
 * can run far longer than any HTTP request or client timeout, so by DEFAULT this
 * DISPATCHES the work to the long-running Horizon queue (timeout=0): the command
 * returns immediately, the job runs to completion in the worker regardless of any
 * client disconnect, and it is resumable (seeded_at short-circuits the seed; the cold
 * cursor resumes the drain). Watch progress at GET /federation/cluster/sync-progress.
 *
 *   php artisan federation:resume-join            # dispatch to the long-running queue (recommended)
 *   php artisan federation:resume-join --sync     # run inline (blocks until done — for a foreground box)
 */
class FederationResumeJoinCommand extends Command
{
    protected $signature = 'federation:resume-join
                            {--sync : run the seed + drain inline instead of dispatching to the queue}';

    protected $description = 'Resume an in-progress mirror join (seed + drain) — dispatched to the long-running queue by default so it survives client/HTTP timeouts';

    public function handle(MirrorService $mirror): int
    {
        $membership = ClusterMembership::query()
            ->where('role', ClusterMembership::ROLE_MIRROR)
            ->whereNotIn('state', [ClusterMembership::STATE_DEPARTED, ClusterMembership::STATE_REJECTED])
            ->latest('updated_at')
            ->first();

        if ($membership === null || $membership->peer === null) {
            $this->error('No active mirror membership to resume — join a cluster first.');

            return self::FAILURE;
        }

        if ($this->option('sync')) {
            $this->info("Resuming inline for membership {$membership->id} (this blocks until the seed + drain finish)…");
            $mirror->syncMembership($membership);
            $this->info('Done — '.$membership->refresh()->state.'.');

            return self::SUCCESS;
        }

        ClusterJoinJob::dispatch((string) $membership->id);

        $this->info("Dispatched the seed + drain to the long-running queue for membership {$membership->id}.");
        $this->line('  • It runs in Horizon with no HTTP/client timeout, and is resumable on re-run.');
        $this->line('  • Ensure the Horizon worker is up (the long-running supervisor).');
        $this->line('  • Watch progress: GET /federation/cluster/sync-progress (or poll the jurisdictions count + seeded_at).');

        return self::SUCCESS;
    }
}
