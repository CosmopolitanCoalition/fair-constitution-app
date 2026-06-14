<?php

namespace Tests\Constitutional;

use App\Models\ClusterMembership;
use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Services\Federation\PeerService;
use App\Services\Mirror\MirrorService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G1) mirror membership. The mirror relationship is
 * a first-class object, distinct from a sovereign peer (Phase F) and from
 * authority. The pins:
 *  1. the shared peer upsert defaults to a SOVEREIGN edge — the Phase F handshake
 *     stays byte-identical — and discriminates a `host` edge on request;
 *  2. becoming a mirror flips isMirror() and points `mirror_of_server_id` AT the
 *     host — it claims NO authority (a mirror is authoritative for nothing);
 *  3. an instance read-only-mirrors AT MOST ONE host at a time.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 *
 * Live-pg posture: guarded connection set as default, one rolled-back txn.
 */
class MirrorMembershipModelTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_mirror_model';

    public function test_upsert_trusted_peer_defaults_to_sovereign_and_can_pin_a_host_edge(): void
    {
        $this->onLivePg(function () {
            $peers = app(PeerService::class);

            $sovereign = $peers->upsertTrustedPeer((string) Str::uuid(), 'pk-sovereign');
            $this->assertSame(FederationPeer::RELATION_SOVEREIGN, $sovereign->relation, 'the default edge is sovereign (Phase F unchanged)');
            $this->assertTrue($sovereign->isSovereign());
            $this->assertSame(FederationPeer::STATUS_TRUST_ESTABLISHED, $sovereign->status);

            $host = $peers->upsertTrustedPeer((string) Str::uuid(), 'pk-host', [], FederationPeer::RELATION_HOST);
            $this->assertSame(FederationPeer::RELATION_HOST, $host->relation);
            $this->assertFalse($host->isSovereign(), 'a host edge is not sovereign');
        });
    }

    public function test_becoming_a_mirror_flips_is_mirror_and_claims_no_authority(): void
    {
        $this->onLivePg(function () {
            $mirror = app(MirrorService::class);
            $hostServerId = (string) Str::uuid();

            $this->assertFalse(InstanceSettings::current()->isMirror(), 'a fresh instance is not a mirror');

            $host = $mirror->pinHost($hostServerId, 'pk-host');
            $this->assertSame(FederationPeer::RELATION_HOST, $host->relation, 'the host we mirror is a host edge');

            $membership = $mirror->openMirrorMembership($host, ClusterMembership::ADMISSION_JOIN_KEY);
            $this->assertSame(ClusterMembership::ROLE_MIRROR, $membership->role, 'our role is mirror');
            $this->assertSame(ClusterMembership::STATE_REQUESTED, $membership->state);
            $this->assertFalse(InstanceSettings::current()->isMirror(), 'not a mirror until adoption is committed');

            $mirror->markMirrorLive($membership, $hostServerId);

            $this->assertSame(ClusterMembership::STATE_LIVE, $membership->refresh()->state);
            $settings = InstanceSettings::current();
            $this->assertTrue($settings->isMirror(), 'isMirror() flips on adoption');
            $this->assertSame($hostServerId, $settings->mirror_of_server_id, 'mirror_of_server_id points AT the host');

            // Authoritative-for-nothing: we point AT a host and hold a `mirror`
            // role — we never claim authority. No authority claim rides this path.
            $this->assertSame(
                0,
                DB::table('authority_claims')->where('claimed_by_peer_id', $host->id)->count(),
                'a mirror claims no authority'
            );
        });
    }

    public function test_only_one_active_mirror_is_permitted(): void
    {
        $this->onLivePg(function () {
            $mirror = app(MirrorService::class);

            $hostA = $mirror->pinHost((string) Str::uuid(), 'pk-a');
            $mirror->openMirrorMembership($hostA, ClusterMembership::ADMISSION_JOIN_KEY);

            $hostB = $mirror->pinHost((string) Str::uuid(), 'pk-b');

            $threw = false;
            try {
                // A savepoint so the constraint violation rolls back to here, not
                // the whole live-pg test transaction.
                DB::transaction(fn () => $mirror->openMirrorMembership($hostB, ClusterMembership::ADMISSION_JOIN_KEY));
            } catch (QueryException $e) {
                $threw = true;
                $this->assertStringContainsStringIgnoringCase('one_active_mirror', $e->getMessage());
            }

            $this->assertTrue($threw, 'a second active mirror membership must be rejected');
            $this->assertSame(
                1,
                ClusterMembership::query()->where('role', ClusterMembership::ROLE_MIRROR)->count(),
                'exactly one mirror membership survives'
            );
        });
    }

    /** ColdSyncChunkingTest posture: guarded live connection, one rolled-back txn. */
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
