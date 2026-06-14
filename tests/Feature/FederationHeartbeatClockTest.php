<?php

namespace Tests\Feature;

use App\Jobs\Federation\FederationHeartbeatJob;
use App\Models\SyncLogEntry;
use App\Services\ClockService;
use App\Services\Federation\FederationSyncService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\PeerService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * F4 CLK-20 — the federation heartbeat is wired into the constitutional
 * scheduler: firing an armed CLK-20 timer dispatches FederationHeartbeatJob, and
 * the job pings every trusted peer (recording liveness) and pushes our FF&C tail.
 *
 * Live-pg posture: guarded connection set as default, one rolled-back txn.
 */
class FederationHeartbeatClockTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_fed_heartbeat';

    public function test_firing_clk20_dispatches_the_heartbeat_job(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            Bus::fake([FederationHeartbeatJob::class]);

            $clocks = app(ClockService::class);
            $timer = $clocks->arm('CLK-20', firesAt: now()->subMinute());

            $this->assertTrue($clocks->fire($timer), 'an armed CLK-20 timer fires');

            Bus::assertDispatched(FederationHeartbeatJob::class);
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }

    public function test_the_heartbeat_job_pings_trusted_peers_and_pushes(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            Http::fake(['*' => Http::response(['result' => 'applied'], 200)]);

            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);

            $peer = $this->makeTrustedPeer();

            (new FederationHeartbeatJob(null))->handle(
                app(PeerService::class),
                app(FederationSyncService::class),
                app(ClockService::class),
                app(\App\Services\Federation\ColdSyncService::class),
            );

            $this->assertNotNull($peer->refresh()->last_heartbeat_at, 'the trusted peer was pinged');
            $this->assertTrue(
                SyncLogEntry::query()->where('peer_id', $peer->id)
                    ->where('direction', SyncLogEntry::DIRECTION_OUTBOUND)->exists(),
                'an outbound push is ledgered'
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }
}
