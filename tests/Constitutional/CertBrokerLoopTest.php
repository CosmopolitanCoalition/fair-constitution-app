<?php

namespace Tests\Constitutional;

use App\Services\AuditService;
use App\Services\Federation\BrokerAuthorizationService;
use App\Services\Federation\CertClientService;
use App\Services\Federation\CertGrantService;
use App\Services\Federation\InMeshBrokerService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MeshCertBroker\BrokerError;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Mesh Roles & Channels of Trust (★9-11), the broker channel end to end. The SAME
 * Broker::issue() Box C runs, in-mesh: authority_keys sourced from the gossiped broker_authorizations
 * (★8), the cert_grant minted by an authority (★11), the request assembled + signed by the peer (★10).
 * THE INVARIANTS: a request whose grant authority is in the domain's authority_keys + whose CSR asks for
 * exactly the granted name is issued; a grant signed by an authority NOT in broker_authorizations is
 * refused (a peer cannot self-authorize, an authority for nothing cannot grant). Offline via the stub
 * ACME (self-signed) — a live LE cert is the rig leg.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class CertBrokerLoopTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_certloop';
    private const DOMAIN = 'worldofstatecraft.org';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cga.broker.domains' => [self::DOMAIN => ['cloudflare_zone_id' => 'zone-test']],
            'cga.broker.acme.provider' => 'stub',
            'cga.broker.store_dsn' => 'sqlite::memory:',
            'services.cloudflare.dns_token' => 'test-token-never-federates',
        ]);
    }

    public function test_an_authorized_grant_issues_a_cert_for_exactly_the_granted_name(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();

            // This box attests ITSELF as an authorized authority + broker for the domain (★8).
            app(BrokerAuthorizationService::class)->attest(self::DOMAIN, $identity->serverId());

            // The authority mints a cert_grant (★11); the peer assembles + signs the request (★10).
            $minted = app(CertGrantService::class)->mint(self::DOMAIN, 'boxa', $identity->serverId(), $identity->publicKey());
            $fqdn = 'boxa.'.self::DOMAIN;
            $kc = app(CertClientService::class)->generateKeyAndCsr($fqdn);
            $body = app(CertClientService::class)->buildRequest($minted['grant'], $minted['grant_signature'], $kc['csr']);

            // The in-mesh broker (★9) runs the same trust chain + issues (stub ACME, offline).
            $result = app(InMeshBrokerService::class)->issue($body);

            $this->assertSame($fqdn, $result['fqdn']);
            $this->assertStringContainsString('BEGIN CERTIFICATE', (string) $result['certificate']);
        });
    }

    public function test_a_grant_from_an_unauthorized_authority_is_refused(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();

            // Only WE are attested for the domain. A stranger authority is not in authority_keys.
            app(BrokerAuthorizationService::class)->attest(self::DOMAIN, $identity->serverId());

            $kp = sodium_crypto_sign_keypair();
            $strangerPub = sodium_bin2base64(sodium_crypto_sign_publickey($kp), SODIUM_BASE64_VARIANT_ORIGINAL);
            $strangerSecret = sodium_crypto_sign_secretkey($kp);
            $sign = fn (string $m) => sodium_bin2base64(sodium_crypto_sign_detached($m, $strangerSecret), SODIUM_BASE64_VARIANT_ORIGINAL);

            $grant = [
                'v' => 1, 'type' => 'cert_grant', 'domain' => self::DOMAIN, 'subdomain' => 'evil',
                'peer_pubkey' => $strangerPub, 'peer_server_id' => (string) Str::uuid(),
                'authority_pubkey' => $strangerPub, 'authority_server_id' => (string) Str::uuid(),
                'issued_at' => now()->getTimestamp(), 'expires_at' => now()->addDay()->getTimestamp(),
            ];
            $grantSig = $sign(AuditService::canonicalJson($grant));
            $kc = app(CertClientService::class)->generateKeyAndCsr('evil.'.self::DOMAIN);

            $body = [
                'grant' => $grant, 'grant_signature' => $grantSig, 'csr' => $kc['csr'],
                'nonce' => bin2hex(random_bytes(16)), 'requested_at' => now()->getTimestamp(),
            ];
            $body['request_signature'] = $sign(AuditService::canonicalJson($body));

            $this->assertThrows(fn () => app(InMeshBrokerService::class)->issue($body), BrokerError::class);
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
