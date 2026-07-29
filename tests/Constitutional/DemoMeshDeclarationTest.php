<?php

namespace Tests\Constitutional;

use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\PeerService;
use App\Support\GameMode;
use App\Support\InstanceClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the demo-mesh DECLARATION carriage (ruling 2026-07-28,
 * V3_SYNTHESIS_PLAN §10 item 4). The dev-time rail counts a peer as demo ONLY
 * when its signed exchange declared it, reading the peer row's
 * metadata instance_class / game_mode. This pins the carriage itself:
 *
 *   1. the signed handshake payload ADVERTISES game_mode (instance_class was
 *      already pinned in ClassScopedFederationTest);
 *   2. a received handshake PERSISTS a declared sandbox mode on the peer row;
 *   3. a later exchange that carries no mode PRESERVES the earlier declaration
 *      (the adoption exchange idiom — a silent upsert never erases a signed
 *      declaration);
 *   4. an unknown declared value is stored as NULL — undeclared — never as
 *      sandbox: absence and garbage both read as REAL downstream, the
 *      fail-closed direction.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class DemoMeshDeclarationTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_demo_mesh_decl';

    protected function tearDown(): void
    {
        InstanceClass::flush();
        GameMode::flush();
        parent::tearDown();
    }

    public function test_the_handshake_payload_advertises_our_game_mode(): void
    {
        $this->onLivePg(function () {
            GameMode::override(GameMode::SANDBOX);

            $payload = app(InstanceIdentityService::class)->handshakePayload();

            $this->assertArrayHasKey(
                'game_mode',
                $payload,
                'the mode must ride the signed handshake — a peer cannot read our local column'
            );
            $this->assertSame(GameMode::SANDBOX, $payload['game_mode']);

            GameMode::override(GameMode::PRODUCTION);
            $this->assertSame(
                GameMode::PRODUCTION,
                app(InstanceIdentityService::class)->handshakePayload()['game_mode']
            );
        });
    }

    public function test_a_received_handshake_persists_a_declared_sandbox_mode(): void
    {
        $this->onLivePg(function () {
            // The faked peer declares no class (= production), so the local
            // side must be production-classed for the symmetric class rule.
            InstanceClass::override(InstanceClass::PRODUCTION);

            $peerId = (string) Str::uuid();
            app(PeerService::class)->receiveHandshake([
                'server_id' => $peerId,
                'public_key' => 'cGVlci1rZXk=',
                'name' => 'Sandbox dev peer',
                'url' => 'https://sandbox-peer.test',
                'game_mode' => 'sandbox',
            ]);

            $this->assertSame(
                'sandbox',
                DB::table('federation_peers')->where('server_id', $peerId)->value('metadata->game_mode'),
                'the declared mode must be persisted to the peer row the dev-time rail reads'
            );
        });
    }

    public function test_a_silent_exchange_preserves_an_earlier_declaration(): void
    {
        $this->onLivePg(function () {
            $service = app(PeerService::class);
            $serverId = (string) Str::uuid();

            $peer = $service->upsertTrustedPeer($serverId, 'test-key', [
                'name' => 'Mode Pin Peer',
                'url' => 'http://mode-pin.test',
                'game_mode' => GameMode::SANDBOX,
            ]);
            $this->assertSame(GameMode::SANDBOX, $peer->metadata['game_mode'] ?? null);

            // The adoption exchange may carry no mode: preserved, not clobbered.
            $peer = $service->upsertTrustedPeer($serverId, 'test-key', [
                'name' => 'Mode Pin Peer (re-upsert)',
            ]);

            $this->assertSame(
                GameMode::SANDBOX,
                $peer->metadata['game_mode'] ?? null,
                'a mode-less upsert must preserve the previously-declared mode, '
                .'exactly as instance_class and matrix_server_name are preserved'
            );
        });
    }

    public function test_garbage_and_absence_both_normalize_to_undeclared_never_sandbox(): void
    {
        foreach ([null, '', 'demo', 'SANDBOX', 'scale_demo', 'banana', 0, false, []] as $value) {
            $this->assertNull(
                GameMode::normalize($value),
                sprintf('[%s] must normalize to NULL (undeclared)', var_export($value, true))
            );
        }

        $this->assertSame(GameMode::SANDBOX, GameMode::normalize('sandbox'));
        $this->assertSame(GameMode::PRODUCTION, GameMode::normalize('production'));
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            app(InstanceIdentityService::class)->ensureIdentity();
            $body();
        } finally {
            InstanceClass::flush();
            GameMode::flush();
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
