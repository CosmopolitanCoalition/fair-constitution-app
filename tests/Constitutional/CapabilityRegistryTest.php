<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\InstanceCapability;
use App\Services\Federation\CapabilityService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Mesh Roles & Channels of Trust (★1-3), the capability registry. A box's role is
 * the derived SET of enabled channels (sibling of the transport registry). THE LOAD-BEARING RULE: a
 * SELF-ASSERTED channel (mesh.member/mirror/etl) self-registers; a GOVERNED channel (broker.*, authority.
 * grant, matrix.homeserver, voice.sfu, client.serve) is enabled ONLY with a grant — never self-asserted,
 * so a box can never advertise a governed role the mesh hasn't approved. Peer manifests are learned
 * idempotently, unknown labels skipped, the DB CHECK pins the closed vocabulary.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class CapabilityRegistryTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_capabilities';

    public function test_self_asserted_registers_but_a_governed_channel_refuses_self_assertion(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $svc = app(CapabilityService::class);

            $svc->registerSelf('mesh.member');
            $this->assertTrue($svc->holds(app(InstanceIdentityService::class)->serverId(), 'mesh.member'));

            $threw = false;
            try {
                $svc->registerSelf('broker.tls'); // governed
            } catch (ConstitutionalViolation $e) {
                $threw = true;
            }
            $this->assertTrue($threw, 'a governed channel cannot be self-asserted — it needs a grant');
        });
    }

    public function test_a_governed_channel_enables_only_with_its_grant_receipt(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $svc = app(CapabilityService::class);
            $authId = (string) Str::uuid();

            $svc->grantSelf('broker.tls', $authId, 'sig-b64', now()->addDays(90)->getTimestamp());

            $manifest = collect($svc->selfCapabilities())->firstWhere('capability', 'broker.tls');
            $this->assertNotNull($manifest, 'the granted governed channel is advertised');
            $this->assertSame($authId, $manifest['granted_by_server_id'], 'the manifest carries the grant receipt');
            $this->assertNotNull($manifest['grant_signature']);
            $this->assertNotNull($manifest['grant_expires_at']);
        });
    }

    public function test_the_closed_vocabulary_is_pinned_at_the_service_and_the_db(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $svc = app(CapabilityService::class);

            $this->assertThrows(fn () => $svc->registerSelf('bogus.channel'), ConstitutionalViolation::class);

            // The DB CHECK is the backstop even for a raw insert.
            $threw = false;
            try {
                DB::table('instance_capabilities')->insert([
                    'id' => (string) Str::uuid(), 'server_id' => (string) Str::uuid(),
                    'capability' => 'bogus.channel', 'is_self' => true, 'enabled' => true, 'priority' => 100,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                $threw = true;
            }
            $this->assertTrue($threw, 'the DB CHECK rejects an off-vocabulary capability');
        });
    }

    public function test_peer_capabilities_are_learned_skipping_unknown_and_idempotently(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $svc = app(CapabilityService::class);
            $peer = (string) Str::uuid();

            $advert = [
                ['capability' => 'matrix.homeserver', 'priority' => 90, 'granted_by_server_id' => (string) Str::uuid(), 'grant_signature' => 's', 'grant_expires_at' => now()->addDay()->getTimestamp()],
                ['capability' => 'bogus.channel'], // skipped
                ['capability' => 'mesh.member'],
            ];
            $svc->recordPeerCapabilities($peer, $advert);
            $svc->recordPeerCapabilities($peer, $advert); // idempotent re-advert

            $this->assertSame(2, InstanceCapability::query()->where('server_id', $peer)->count(), 'unknown skipped, no duplicates');
            $this->assertTrue($svc->holds($peer, 'matrix.homeserver'));
            $this->assertContains($peer, $svc->holdersOf('matrix.homeserver'));
            $this->assertFalse($svc->holds($peer, 'bogus.channel'));
        });
    }

    public function test_disable_self_drops_the_channel_from_the_manifest(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $svc = app(CapabilityService::class);

            $svc->registerSelf('etl');
            $this->assertNotNull(collect($svc->selfCapabilities())->firstWhere('capability', 'etl'));

            $svc->disableSelf('etl');
            $this->assertNull(collect($svc->selfCapabilities())->firstWhere('capability', 'etl'), 'disabled ⇒ not advertised');
        });
    }

    public function test_the_manifest_rides_the_handshake_learned_and_advertised_back(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $svc = app(CapabilityService::class);
            $svc->registerSelf('mesh.member'); // we will advertise this back

            $peerServerId = (string) Str::uuid();
            $response = app(\App\Services\Federation\PeerService::class)->receiveHandshake([
                'server_id'  => $peerServerId,
                'public_key' => sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_ORIGINAL),
                'name'       => 'Peer with roles',
                'capabilities' => [
                    ['capability' => 'matrix.homeserver', 'priority' => 90],
                    ['capability' => 'mesh.member'],
                ],
            ]);

            // We LEARNED the peer's advertised capabilities.
            $this->assertTrue($svc->holds($peerServerId, 'matrix.homeserver'), 'the peer manifest is learned on handshake');

            // We ADVERTISED ours back (symmetric), and it rides the same signed payload as transports.
            $advertised = collect($response['capabilities'] ?? [])->pluck('capability')->all();
            $this->assertContains('mesh.member', $advertised, 'our capability manifest is returned in the handshake');
            $this->assertArrayHasKey('transports', $response, 'capabilities sit alongside transports on the signed payload');
        });
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        app(RoleService::class)->flush();
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            app(RoleService::class)->flush();
        }
    }
}
