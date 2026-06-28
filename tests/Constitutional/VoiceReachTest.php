<?php

namespace Tests\Constitutional;

use App\Models\FederationPeer;
use App\Models\InstanceCapability;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\Federation\MultiplexClient;
use App\Services\Federation\NoReachableHolder;
use App\Services\Federation\ServiceReachService;
use App\Services\Matrix\VoiceReachService;
use App\Services\RoleService;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — foci AV reach, the HOME node (L) side (Phase 5). Given a player + a room, the home
 * node returns a LiveKit {token, sfu_url}: minted LOCALLY when this node hosts the SFU, else FORWARDED to a
 * capable peer via a short-TTL home-attested envelope (the peer mints with its own secret). No reachable
 * SFU SAFE-DEGRADES (NoReachableHolder propagates), never a hard fail. The identity is always the
 * pseudonym; the forwarded envelope carries the attested actor, never a residency/jurisdiction claim.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class VoiceReachTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_voicereach';

    public function test_local_node_mints_directly_when_it_hosts_the_sfu(): void
    {
        $this->onLivePg(function () {
            $this->clearSlate();
            $player = $this->player('localvoicer');
            $identity = app(\App\Services\Federation\InstanceIdentityService::class);
            $identity->ensureIdentity();

            // WE host voice.sfu.
            InstanceCapability::create([
                'server_id' => $identity->serverId(), 'capability' => 'voice.sfu',
                'is_self' => true, 'enabled' => true, 'priority' => 100,
            ]);

            $r = app(VoiceReachService::class)->tokenFor($player, (string) Str::uuid(), 'call-square', []);

            $this->assertSame('local', $r['via']);
            $this->assertSame('@u-localvoicer:'.config('matrix.server_name'), $r['identity']);
            $this->assertNotEmpty($r['token']);
            $this->assertNotEmpty($r['sfu_url']);
        });
    }

    public function test_cross_node_forwards_an_attested_envelope_to_the_capable_peer(): void
    {
        $this->onLivePg(function () {
            $this->clearSlate();
            $player = $this->player('traveler');
            $peer = $this->peerHoldingVoiceSfu();

            // Capture what we forward; return the peer's minted token.
            $captured = null;
            $mux = Mockery::mock(MultiplexClient::class);
            $mux->shouldReceive('reach')->once()->andReturnUsing(function ($id, $m, $path, $payload) use (&$captured, $peer) {
                $captured = ['id' => $id, 'path' => $path, 'payload' => $payload];

                return new ClientResponse(new GuzzleResponse(200, [], (string) json_encode([
                    'token' => 'PEER.MINTED.TOKEN', 'sfu_url' => 'wss://peer.example/sfu', 'room' => 'call-square',
                ])));
            });
            app()->instance(MultiplexClient::class, $mux);

            $r = app(VoiceReachService::class)->tokenFor($player, (string) Str::uuid(), 'call-square', [
                'device_public_key' => 'dev-pubkey', 'action_signature' => 'dev-sig', 'timestamp' => now()->getTimestamp(),
            ]);

            // The peer's token is relayed; we dial the PEER's SFU.
            $this->assertSame((string) $peer->server_id, $r['via']);
            $this->assertSame('PEER.MINTED.TOKEN', $r['token']);
            $this->assertSame('wss://peer.example/sfu', $r['sfu_url']);

            // We forwarded an attested envelope to the peer's /voice/token — carrying the actor + pseudonym,
            // NEVER a residency/jurisdiction claim (the commons is open).
            $this->assertSame((string) $peer->server_id, $captured['id']);
            $this->assertSame('/api/federation/voice/token', $captured['path']);
            $this->assertSame('call-square', $captured['payload']['room']);
            $actor = $captured['payload']['actor'];
            $this->assertSame('@u-traveler:'.config('matrix.server_name'), $actor['pseudonym']);
            $this->assertSame((string) $player->id, $actor['attestation']['subject_user_id']);
            $this->assertArrayHasKey('signature', $actor['attestation']);
            $this->assertStringNotContainsString('jurisdiction', (string) json_encode($actor), 'no jurisdiction in the envelope');
        });
    }

    public function test_safe_degrades_when_no_sfu_is_reachable(): void
    {
        $this->onLivePg(function () {
            $this->clearSlate(); // nobody hosts voice.sfu
            $player = $this->player('lonely');

            $threw = false;
            try {
                app(VoiceReachService::class)->tokenFor($player, (string) Str::uuid(), 'call-square', []);
            } catch (NoReachableHolder $e) {
                $threw = true;
                $this->assertSame('voice.sfu', $e->capability);
            }
            $this->assertTrue($threw, 'no reachable SFU degrades (the player endpoint maps this to a clean 503)');
        });
    }

    private function clearSlate(): void
    {
        InstanceCapability::query()
            ->whereIn('capability', ServiceReachService::LIVE_SERVICE_CHANNELS)
            ->update(['enabled' => false]);
    }

    private function player(string $handle): User
    {
        $user = User::factory()->create(['home_server_id' => null]); // home = us → issue() allows
        SocialProfile::create(['user_id' => (string) $user->id, 'handle' => $handle, 'visibility' => 'public']);

        return $user;
    }

    private function peerHoldingVoiceSfu(): FederationPeer
    {
        $peer = FederationPeer::create([
            'server_id' => (string) Str::uuid(),
            'name' => 'SFU '.Str::random(4),
            'url' => 'https://sfu-peer.example',
            'public_key' => 'k-'.Str::random(8),
            'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
            'trust_established_at' => now(),
            'metadata' => ['schema_version' => '1'],
        ]);
        InstanceCapability::create([
            'server_id' => (string) $peer->server_id, 'capability' => 'voice.sfu',
            'is_self' => false, 'enabled' => true, 'priority' => 100,
        ]);

        return $peer;
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
