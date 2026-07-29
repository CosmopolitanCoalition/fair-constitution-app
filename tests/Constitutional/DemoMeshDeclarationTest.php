<?php

namespace Tests\Constitutional;

use App\Models\ClusterAdoptionRequest;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\PeerService;
use App\Services\Mirror\MirrorService;
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
 *      fail-closed direction;
 *   5. the KEYLESS adoption queue carries the declaration across the pending
 *      wait (declared_* columns on cluster_adoption_requests) so approveRequest
 *      pins the mirror peer exactly as the keyed path does — the gap 7b09915
 *      flagged, now closed by 2026_07_29_140000.
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

    /**
     * THE KEYLESS-QUEUE GAP, CLOSED. A would-be mirror that requests adoption
     * without a join key sits PENDING until the host operator vouches it. Its
     * signed declaration must survive that wait on the request row and be pinned
     * on the peer at approval — otherwise a queue-admitted demo mirror records no
     * demo-ness and the dev-time rail reads the whole mesh as real.
     */
    public function test_a_keyless_queued_adoption_carries_the_declaration_to_the_peer(): void
    {
        $this->onLivePg(function () {
            $mirror = app(MirrorService::class);
            $applicantId = (string) Str::uuid();

            // The applicant declares a demo mesh (scale_demo + sandbox) in its
            // signed adopt body; the host queues the request.
            $request = $mirror->requestAdoption(
                $applicantId,
                'applicant-key',
                'https://applicant.test',
                [],
                ['instance_class' => InstanceClass::SCALE_DEMO, 'game_mode' => GameMode::SANDBOX],
            );

            // The declaration is recorded on the pending request row (normalized).
            $this->assertSame(ClusterAdoptionRequest::STATUS_PENDING, $request->status);
            $this->assertSame(InstanceClass::SCALE_DEMO, $request->declared_instance_class);
            $this->assertSame(GameMode::SANDBOX, $request->declared_game_mode);

            // The operator vouches it — the peer row inherits the declaration,
            // so a mirror of this host counts as demo-mesh membership.
            $mirror->approveRequest($request->id);

            $meta = DB::table('federation_peers')->where('server_id', $applicantId)->value('metadata');
            $meta = is_string($meta) ? json_decode($meta, true) : (array) $meta;
            $this->assertSame(InstanceClass::SCALE_DEMO, $meta['instance_class'] ?? null,
                'the vouched mirror peer carries the class declared at request time');
            $this->assertSame(GameMode::SANDBOX, $meta['game_mode'] ?? null,
                'the vouched mirror peer carries the mode declared at request time');
        });
    }

    /**
     * The fail-closed direction on the same path: an applicant that declared
     * NOTHING leaves the request columns NULL and the peer undeclared — a
     * queue-admitted mirror with no signed demo-ness reads as REAL, never
     * silently promoted to a demo by the carriage.
     */
    public function test_a_keyless_undeclared_applicant_stays_undeclared_through_the_queue(): void
    {
        $this->onLivePg(function () {
            $mirror = app(MirrorService::class);
            $applicantId = (string) Str::uuid();

            $request = $mirror->requestAdoption($applicantId, 'applicant-key', 'https://plain.test');

            $this->assertNull($request->declared_instance_class, 'no class declared → NULL, not production');
            $this->assertNull($request->declared_game_mode);

            $mirror->approveRequest($request->id);

            $meta = DB::table('federation_peers')->where('server_id', $applicantId)->value('metadata');
            $meta = is_string($meta) ? json_decode($meta, true) : (array) $meta;
            $this->assertNull($meta['instance_class'] ?? null,
                'an undeclared applicant is NOT promoted to a class by the carriage');
            $this->assertNull($meta['game_mode'] ?? null);
        });
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
