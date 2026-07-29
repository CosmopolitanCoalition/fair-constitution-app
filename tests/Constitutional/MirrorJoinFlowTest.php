<?php

namespace Tests\Constitutional;

use App\Models\ClusterMembership;
use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Models\SyncCursor;
use App\Services\Dev\DemoMeshTimeCoordinator;
use App\Services\Federation\FederationSyncService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Mirror\MirrorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G2) the mirror-side join. `cluster:join` → adopt
 * → backfill the host corpus in bounded signed pages → flip into read-only-mirror
 * mode. The host's /adopt + /audit-tail are faked; the pages are signed by us and
 * verify against the host peer's pinned key (the cold-sync trick). On a caught-up
 * backfill the instance becomes a mirror — and (G2 write-guard) authoritative for
 * nothing.
 *
 * If an edit breaks this, the edit is the violation — fix the edit, not the test.
 */
class MirrorJoinFlowTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_join_flow';

    public function test_joining_a_host_backfills_in_chunks_and_becomes_a_mirror(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);
            $sync = app(FederationSyncService::class);

            config(['cga.federation_sync_page_size' => 20]);

            $hostServerId = (string) Str::uuid();
            // Anchor the host peer ~60 ROWS back so the drain is multi-page but
            // bounded (seq RANGE is gappy — rolled-back tests burn seqs).
            $start = (int) (DB::table('audit_log')->orderByDesc('seq')->offset(60)->limit(1)->value('seq') ?? 0);

            // Pre-pin the host with a backfill start watermark; pinHost (via
            // upsertTrustedPeer) preserves peer_head_seq on an existing row.
            FederationPeer::create([
                'server_id' => $hostServerId,
                'name' => 'Cluster host',
                'url' => 'http://host.docker.internal:9990',
                'public_key' => $identity->publicKey(),
                'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
                'trust_established_at' => now(),
                'relation' => FederationPeer::RELATION_HOST,
                'peer_head_seq' => $start,
            ]);

            // A never-deliberately-named node adopts the HOST's display name on going live
            // (one mesh = one game — the citizen header should read the game, not the node).
            InstanceSettings::current()->forceFill(['instance_name' => 'Unnamed Instance'])->save();

            // Fake the host: /adopt returns OUR key as the host key; /audit-tail
            // returns real signed pages of our own chain.
            Http::fake([
                '*/api/federation/adopt' => Http::response([
                    'admitted' => true,
                    'host_server_id' => $hostServerId,
                    'host_public_key' => $identity->publicKey(),
                    'host_name' => 'United Earth (host)',
                    'scope_jurisdiction_id' => null,
                ], 200),
                '*/api/federation/audit-tail*' => function ($request) use ($sync) {
                    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

                    return Http::response($sync->buildAuditTail((int) ($q['from_seq'] ?? 0), (int) ($q['page_size'] ?? 500)), 200);
                },
            ]);

            $membership = app(MirrorService::class)->joinHost('http://host.docker.internal:9990', 'somehandle.somesecret');

            $this->assertSame(ClusterMembership::ROLE_MIRROR, $membership->role);
            $this->assertSame(ClusterMembership::STATE_LIVE, $membership->state, 'a caught-up backfill goes live');
            $this->assertTrue(InstanceSettings::current()->isMirror(), 'the instance is now a read-only mirror');
            $this->assertSame($hostServerId, InstanceSettings::current()->mirror_of_server_id);
            $this->assertSame('United Earth (host)', InstanceSettings::current()->fresh()->instance_name,
                'an unnamed mirror adopts the host display name on going live (one mesh = one game)');

            // The backfill ran in pages and completed.
            $cursor = SyncCursor::query()->where('peer_id', $membership->peer_id)
                ->where('mode', SyncCursor::MODE_COLD)->orderByDesc('created_at')->first();
            $this->assertNotNull($cursor, 'a cold-sync cursor was opened');
            $this->assertSame(SyncCursor::STATUS_COMPLETE, $cursor->status, 'the backfill caught up');
            $this->assertGreaterThanOrEqual(1, (int) $cursor->pages_applied, 'the corpus pulled in pages');
        });
    }

    /**
     * DEMO-MESH TIME COORDINATION (Wave 4 ③, "coordinating node"). A mirror of a DECLARED demo
     * host adopts that host's time coordinator on join — one mesh = one game = one clock — so a
     * multibox demo advances from exactly one node with no per-follower setup. This completes the
     * Wave 3 coordinator, which learned a follower's coordinator only via manual setCoordinator().
     */
    public function test_a_mirror_of_a_demo_host_adopts_the_hosts_time_coordinator(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);
            $sync = app(FederationSyncService::class);

            $hostServerId = (string) Str::uuid();
            $meshCoordinator = (string) Str::uuid(); // the host names its coordinator (itself, upstream, whatever)

            $this->fakeHost($sync, $hostServerId, [
                // The host declares demo — a mirror of a demo host is demo-mesh membership.
                'host_instance_class' => 'scale_demo',
                'host_time_coordinator_server_id' => $meshCoordinator,
            ]);

            app(MirrorService::class)->joinHost('http://host.docker.internal:9990', 'somehandle.somesecret');

            $this->assertSame(
                $meshCoordinator,
                InstanceSettings::current()->fresh()->time_coordinator_server_id,
                'a mirror of a demo host adopts the host-advertised coordinator on join'
            );
            $this->assertTrue(
                app(DemoMeshTimeCoordinator::class)->isFollower(),
                'having adopted a coordinator, this node is a follower — it replays, never originates'
            );
        });
    }

    /**
     * SAFETY GATE (the same pin, refused direction). A mirror of a NON-demo host must NEVER adopt a
     * coordinator: a production node does not time-travel, and hijacking its clock from a peer's
     * advertisement would be a live-instance defect. Absence of a declaration reads as production.
     */
    public function test_a_mirror_of_a_production_host_never_adopts_a_coordinator(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);
            $sync = app(FederationSyncService::class);

            // This node coordinates itself (NULL) going in.
            $this->assertTrue(app(DemoMeshTimeCoordinator::class)->isCoordinator());

            $hostServerId = (string) Str::uuid();
            $this->fakeHost($sync, $hostServerId, [
                // No host_instance_class / host_game_mode → the host reads as PRODUCTION. It still
                // advertises a coordinator id, which the mirror must IGNORE.
                'host_time_coordinator_server_id' => (string) Str::uuid(),
            ]);

            app(MirrorService::class)->joinHost('http://host.docker.internal:9990', 'somehandle.somesecret');

            $this->assertNull(
                InstanceSettings::current()->fresh()->time_coordinator_server_id,
                'a mirror of a production host must not adopt any coordinator — its clock is its own'
            );
            $this->assertTrue(
                app(DemoMeshTimeCoordinator::class)->isCoordinator(),
                'the node still coordinates itself; a production peer never flips it to a follower'
            );
        });
    }

    /**
     * Fake a host that admits us and serves signed pages of our own chain (the cold-sync trick from
     * the primary pin). $adoptExtra layers extra keys onto the /adopt response body.
     *
     * @param  array<string,mixed>  $adoptExtra
     */
    private function fakeHost(FederationSyncService $sync, string $hostServerId, array $adoptExtra = []): void
    {
        $identity = app(InstanceIdentityService::class);

        config(['cga.federation_sync_page_size' => 20]);
        $start = (int) (DB::table('audit_log')->orderByDesc('seq')->offset(60)->limit(1)->value('seq') ?? 0);

        FederationPeer::create([
            'server_id' => $hostServerId,
            'name' => 'Cluster host',
            'url' => 'http://host.docker.internal:9990',
            'public_key' => $identity->publicKey(),
            'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
            'trust_established_at' => now(),
            'relation' => FederationPeer::RELATION_HOST,
            'peer_head_seq' => $start,
        ]);

        Http::fake([
            '*/api/federation/adopt' => Http::response(array_merge([
                'admitted' => true,
                'host_server_id' => $hostServerId,
                'host_public_key' => $identity->publicKey(),
                'host_name' => 'United Earth (host)',
                'scope_jurisdiction_id' => null,
            ], $adoptExtra), 200),
            '*/api/federation/audit-tail*' => function ($request) use ($sync) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

                return Http::response($sync->buildAuditTail((int) ($q['from_seq'] ?? 0), (int) ($q['page_size'] ?? 500)), 200);
            },
        ]);
    }

    private function onLivePg(callable $body): void
    {
        // This pin is about the audit BACKFILL + mirror flip; the host (faked) advertises no seed, so
        // the seed step is a no-op here. Tarball mode skips a seedless host gracefully (the paginated
        // drain's own join integration is covered by the Foundation* tests + the live campaign).
        config(['cga.federation_seed_transport' => 'tarball']);

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
