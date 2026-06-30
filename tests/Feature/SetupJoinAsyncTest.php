<?php

namespace Tests\Feature;

use App\Jobs\Federation\ClusterJoinJob;
use App\Models\ClusterMembership;
use App\Services\Mirror\MirrorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * The setup-wizard JOIN must run the long seed + drain OFF the request thread (the Gate-2 blocker Box B
 * surfaced: joinFromSetup ran it INLINE, so a client disconnect aborted the handler before seeded_at was
 * stamped and every resume re-did the whole 12 GB). Pins, mirroring the federation console:
 *   • a fresh join ADMITS synchronously then DISPATCHES ClusterJoinJob (never drains inline) → 'syncing';
 *   • a re-submit while still SYNCING re-dispatches the resumable job → 'syncing';
 *   • a re-submit once the background drain is LIVE finalizes setup WITHOUT dispatching → 'ready'.
 *
 * MirrorService is mocked so no real /adopt or multi-GB drain runs; this is purely the controller wiring.
 * Live-pg posture (instance_settings is mutated inside the rolled-back tx).
 */
class SetupJoinAsyncTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_setup_join_async';

    public function test_a_fresh_join_admits_then_defers_the_drain_to_the_background_job(): void
    {
        $this->onLivePg(function () {
            DB::table('instance_settings')->update(['setup_mode' => 'join', 'setup_completed_at' => null, 'mirror_of_server_id' => null]);
            Queue::fake();

            $membership = $this->syncingMembership();

            $this->mock(MirrorService::class, function ($m) use ($membership) {
                $m->shouldReceive('isMirror')->andReturn(false);
                $m->shouldReceive('joinHost')->andReturn($membership);
            });

            $resp = $this->withSession(['_token' => 'pin'])->postJson('/api/setup/join', [
                'host_url' => 'http://host.docker.internal:9990',
                'join_key' => 'handle.secret',
            ], ['X-CSRF-TOKEN' => 'pin']);

            $resp->assertOk()->assertJsonPath('state', 'syncing');
            Queue::assertPushed(ClusterJoinJob::class);
            // The drain was NOT run inline — the membership is still just SYNCING, never seeded here.
            $this->assertNull($membership->refresh()->seeded_at);
        });
    }

    public function test_a_resume_while_syncing_redispatches_the_job(): void
    {
        $this->onLivePg(function () {
            $peer = $this->makeTrustedPeer();
            DB::table('instance_settings')->update(['setup_mode' => 'join', 'setup_completed_at' => null, 'mirror_of_server_id' => $peer->server_id]);
            Queue::fake();

            $membership = $this->syncingMembership($peer->id);

            $this->mock(MirrorService::class, function ($m) use ($membership) {
                $m->shouldReceive('isMirror')->andReturn(true);
                $m->shouldReceive('activeMirrorMembership')->andReturn($membership);
            });

            $resp = $this->withSession(['_token' => 'pin'])->postJson('/api/setup/join', [], ['X-CSRF-TOKEN' => 'pin']);

            $resp->assertOk()->assertJsonPath('state', 'syncing');
            Queue::assertPushed(ClusterJoinJob::class);
        });
    }

    public function test_a_resume_once_live_finalizes_setup_without_dispatching(): void
    {
        $this->onLivePg(function () {
            $peer = $this->makeTrustedPeer();
            DB::table('instance_settings')->update(['setup_mode' => 'join', 'setup_completed_at' => null, 'mirror_of_server_id' => $peer->server_id]);
            Queue::fake();

            $membership = ClusterMembership::create([
                'peer_id' => $peer->id,
                'role' => ClusterMembership::ROLE_MIRROR,
                'state' => ClusterMembership::STATE_LIVE,
                'admission_method' => ClusterMembership::ADMISSION_JOIN_KEY,
            ]);

            $this->mock(MirrorService::class, function ($m) use ($membership) {
                $m->shouldReceive('isMirror')->andReturn(true);
                $m->shouldReceive('activeMirrorMembership')->andReturn($membership);
            });

            $resp = $this->withSession(['_token' => 'pin'])->postJson('/api/setup/join', [], ['X-CSRF-TOKEN' => 'pin']);

            $resp->assertOk()->assertJsonPath('state', 'ready');
            Queue::assertNotPushed(ClusterJoinJob::class);
            $this->assertNotNull(DB::table('instance_settings')->value('setup_completed_at'));
        });
    }

    private function syncingMembership(?string $peerId = null): ClusterMembership
    {
        $peerId ??= $this->makeTrustedPeer()->id;

        return ClusterMembership::create([
            'peer_id' => $peerId,
            'role' => ClusterMembership::ROLE_MIRROR,
            'state' => ClusterMembership::STATE_SYNCING,
            'admission_method' => ClusterMembership::ADMISSION_JOIN_KEY,
        ]);
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
