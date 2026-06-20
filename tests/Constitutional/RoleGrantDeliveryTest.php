<?php

namespace Tests\Constitutional;

use App\Http\Controllers\Federation\RoleGrantController;
use App\Models\FederationPeer;
use App\Services\AuditService;
use App\Services\Federation\CapabilityService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\RoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Mesh Roles & Channels of Trust (★17), cross-instance capability-grant delivery
 * (the JOIN hop). THE INVARIANTS: a grant is applied ONLY if it verifies against the AUTHORITY's OWN
 * pinned key, its claimed authority_pubkey equals that pinned key, AND it names THIS box as the grantee.
 * A pubkey mismatch, a tampered signature, or a grant addressed to a different box is refused — no trust
 * by relay, no grant applied to a box it was not minted for.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class RoleGrantDeliveryTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_rolegrantdelivery';

    public function test_a_grant_addressed_to_us_from_a_pinned_authority_is_applied(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            [$authServerId, $authPub, $sign] = $this->authority();

            $grant = $this->grant('matrix.homeserver', $identity->serverId(), $identity->publicKey(), $authServerId, $authPub);
            $resp = $this->deliver($grant, $sign(AuditService::canonicalJson($grant)), $authServerId, $authPub);

            $this->assertSame(200, $resp->getStatusCode());
            $this->assertTrue(app(CapabilityService::class)->holds($identity->serverId(), 'matrix.homeserver'),
                'a verified, addressed grant flips the channel enabled (JOIN)');
        });
    }

    public function test_a_pubkey_mismatch_a_tamper_and_a_misaddressed_grant_are_all_refused(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            [$authServerId, $authPub, $sign] = $this->authority();

            // 1) authority_pubkey ≠ the pinned key → 403.
            $strangerPub = sodium_bin2base64(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()), SODIUM_BASE64_VARIANT_ORIGINAL);
            $g1 = $this->grant('voice.sfu', $identity->serverId(), $identity->publicKey(), $authServerId, $strangerPub);
            $this->assertSame(403, $this->deliver($g1, $sign(AuditService::canonicalJson($g1)), $authServerId, $authPub)->getStatusCode());

            // 2) tampered signature (sig over a different grant) → 403.
            $g2 = $this->grant('voice.sfu', $identity->serverId(), $identity->publicKey(), $authServerId, $authPub);
            $other = $this->grant('client.serve', $identity->serverId(), $identity->publicKey(), $authServerId, $authPub);
            $this->assertSame(403, $this->deliver($g2, $sign(AuditService::canonicalJson($other)), $authServerId, $authPub)->getStatusCode());

            // 3) grant addressed to a DIFFERENT box → 403.
            $g3 = $this->grant('voice.sfu', (string) Str::uuid(), $identity->publicKey(), $authServerId, $authPub);
            $this->assertSame(403, $this->deliver($g3, $sign(AuditService::canonicalJson($g3)), $authServerId, $authPub)->getStatusCode());

            // None of the refused grants enabled anything.
            $this->assertFalse(app(CapabilityService::class)->holds($identity->serverId(), 'voice.sfu'));
            $this->assertFalse(app(CapabilityService::class)->holds($identity->serverId(), 'client.serve'));
        });
    }

    /** @return array{0:string,1:string,2:callable} [authority_server_id, authority_pubkey, signer] */
    private function authority(): array
    {
        $kp = sodium_crypto_sign_keypair();
        $pub = sodium_bin2base64(sodium_crypto_sign_publickey($kp), SODIUM_BASE64_VARIANT_ORIGINAL);
        $secret = sodium_crypto_sign_secretkey($kp);
        $serverId = (string) Str::uuid();
        FederationPeer::create([
            'server_id' => $serverId, 'name' => 'Authority '.Str::random(5),
            'url' => 'http://auth-'.Str::lower(Str::random(8)).'.invalid', 'public_key' => $pub,
            'status' => FederationPeer::STATUS_TRUST_ESTABLISHED, 'trust_established_at' => now(),
        ]);
        $sign = fn (string $m) => sodium_bin2base64(sodium_crypto_sign_detached($m, $secret), SODIUM_BASE64_VARIANT_ORIGINAL);

        return [$serverId, $pub, $sign];
    }

    private function grant(string $cap, string $peerServerId, string $peerPub, string $authServerId, string $authPub): array
    {
        return [
            'v' => 1, 'type' => 'capability_grant', 'capability' => $cap, 'scope_jurisdiction_id' => (string) Str::uuid(),
            'peer_server_id' => $peerServerId, 'peer_pubkey' => $peerPub,
            'authority_server_id' => $authServerId, 'authority_pubkey' => $authPub,
            'issued_at' => now()->getTimestamp(), 'expires_at' => now()->addDays(90)->getTimestamp(),
        ];
    }

    private function deliver(array $grant, string $grantSig, string $authServerId, string $authPub): \Illuminate\Http\JsonResponse
    {
        $from = FederationPeer::query()->where('server_id', $authServerId)->first();
        $request = Request::create('/api/federation/role-grant', 'POST', [], [], [], [], json_encode([
            'grant' => $grant, 'grant_signature' => $grantSig,
        ]));
        $request->attributes->set('peer', $from);

        return app(RoleGrantController::class)->receiveGrant($request);
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
