<?php

namespace Tests\Constitutional;

use App\Http\Controllers\Federation\CertGrantController;
use App\Models\FederationPeer;
use App\Services\AuditService;
use App\Services\Federation\CertGrantStore;
use App\Services\Federation\InstanceIdentityService;
use App\Services\RoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — cert-grant AUTO-DELIVERY (Mesh Roles, the no-copy-paste fix). An authority pushes a
 * cert_grant to the grantee over the mesh; the grantee VERIFIES it against the authority's OWN pinned key,
 * confirms it is addressed to THIS box, and PERSISTS it so `mesh:request-cert` picks it up without an
 * operator hand-carrying JSON. THE INVARIANTS: a valid, addressed grant is stored + retrievable; a tampered
 * signature, an unpinned authority, or a grant addressed to a DIFFERENT box is refused AND not stored. The
 * broker re-verifies end-to-end at issuance, so this store is a cache, never a trust root.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class CertGrantDeliveryTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_certgrantdelivery';

    protected function tearDown(): void
    {
        @unlink((string) config('cga.broker.received_grants_path'));
        parent::tearDown();
    }

    public function test_a_delivered_addressed_grant_is_persisted_for_the_client(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            @unlink((string) config('cga.broker.received_grants_path'));
            [$authServerId, $authPub, $sign] = $this->authority();

            $grant = $this->grant('worldofstatecraft.org', 'boxb', $identity->publicKey(), $identity->serverId(), $authServerId, $authPub);
            $resp = $this->deliver($grant, $sign(AuditService::canonicalJson($grant)), $authServerId);

            $this->assertSame(200, $resp->getStatusCode());
            $stored = app(CertGrantStore::class)->get('boxb.worldofstatecraft.org');
            $this->assertNotNull($stored, 'the delivered grant is persisted for mesh:request-cert');
            $this->assertSame('cert_grant', $stored['grant']['type']);
            $this->assertContains('boxb.worldofstatecraft.org', app(CertGrantStore::class)->fqdns());
        });
    }

    public function test_tampered_unpinned_or_misaddressed_grants_are_refused_and_not_stored(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            @unlink((string) config('cga.broker.received_grants_path'));
            [$authServerId, $authPub, $sign] = $this->authority();

            // 1) tampered signature (sig over a different grant) → 403.
            $g = $this->grant('worldofstatecraft.org', 'boxb', $identity->publicKey(), $identity->serverId(), $authServerId, $authPub);
            $other = $this->grant('worldofstatecraft.org', 'evil', $identity->publicKey(), $identity->serverId(), $authServerId, $authPub);
            $this->assertSame(403, $this->deliver($g, $sign(AuditService::canonicalJson($other)), $authServerId)->getStatusCode());

            // 2) addressed to a DIFFERENT box (peer_pubkey not ours) → 403.
            $strangerPub = sodium_bin2base64(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()), SODIUM_BASE64_VARIANT_ORIGINAL);
            $g2 = $this->grant('worldofstatecraft.org', 'boxc', $strangerPub, (string) Str::uuid(), $authServerId, $authPub);
            $this->assertSame(403, $this->deliver($g2, $sign(AuditService::canonicalJson($g2)), $authServerId)->getStatusCode());

            // Nothing was stored.
            $this->assertSame([], app(CertGrantStore::class)->fqdns(), 'no refused grant is ever persisted');
        });
    }

    /** @return array{0:string,1:string,2:callable} */
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

        return [$serverId, $pub, fn (string $m) => sodium_bin2base64(sodium_crypto_sign_detached($m, $secret), SODIUM_BASE64_VARIANT_ORIGINAL)];
    }

    private function grant(string $domain, string $sub, string $peerPub, string $peerServer, string $authServer, string $authPub): array
    {
        return [
            'v' => 1, 'type' => 'cert_grant', 'domain' => $domain, 'subdomain' => $sub,
            'peer_pubkey' => $peerPub, 'peer_server_id' => $peerServer,
            'authority_pubkey' => $authPub, 'authority_server_id' => $authServer,
            'issued_at' => now()->getTimestamp(), 'expires_at' => now()->addDays(90)->getTimestamp(),
        ];
    }

    private function deliver(array $grant, string $grantSig, string $authServerId): \Illuminate\Http\JsonResponse
    {
        $from = FederationPeer::query()->where('server_id', $authServerId)->first();
        $request = Request::create('/api/federation/cert-grant', 'POST', [], [], [], [], json_encode([
            'grant' => $grant, 'grant_signature' => $grantSig,
        ]));
        $request->attributes->set('peer', $from);

        return app(CertGrantController::class)->receiveGrant($request);
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
