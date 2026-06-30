<?php

namespace Tests\Feature;

use App\Jobs\Federation\ClusterJoinJob;
use App\Models\ClusterMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * federation:resume-join — the CLI trigger for the background seed + drain. The
 * default path DISPATCHES ClusterJoinJob to the long-running queue (so the work
 * survives client/HTTP timeouts — the Pi-restore-vs-50min-curl blocker). Pins: it
 * dispatches for an active mirror membership, and refuses when there is none.
 *
 * Live-pg posture.
 */
class FederationResumeJoinCommandTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_resume_join';

    public function test_it_dispatches_the_background_job_for_an_active_mirror_membership(): void
    {
        $this->onLivePg(function () {
            Queue::fake();

            $peer = $this->makeTrustedPeer();
            ClusterMembership::create([
                'peer_id' => $peer->id,
                'role' => ClusterMembership::ROLE_MIRROR,
                'state' => ClusterMembership::STATE_SYNCING,
                'admission_method' => ClusterMembership::ADMISSION_JOIN_KEY,
            ]);

            $this->artisan('federation:resume-join')->assertExitCode(0);

            Queue::assertPushed(ClusterJoinJob::class);
        });
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
