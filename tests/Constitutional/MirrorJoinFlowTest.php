<?php

namespace Tests\Constitutional;

use App\Models\ClusterMembership;
use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Models\SyncCursor;
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
