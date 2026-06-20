<?php

namespace Tests\Constitutional;

use App\Models\FederationPeer;
use App\Services\Federation\BrokerAuthorizationService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Mesh Roles & Channels of Trust (★8), the broker routing table. The cert-broker's
 * static per-domain authority_keys whitelist becomes a live, mesh-replicated set of SIGNED facts. THE
 * INVARIANTS: a fact is verified against the AUTHORITY's OWN pinned key (never the relayer's), and its
 * claimed authority_pubkey must equal that pinned key — no trust is conferred by relay; a tampered fact,
 * an unknown authority, or a pubkey mismatch is dropped; authorityKeysFor(domain) yields exactly the
 * GrantVerifier's authority_keys; revoke removes it (fail-closed at read).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class BrokerAuthorizationTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_brokerauth';
    private const DOMAIN = 'worldofstatecraft.org';

    public function test_we_attest_a_broker_and_it_feeds_authority_keys_and_routing(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $svc = app(BrokerAuthorizationService::class);
            $brokerServerId = (string) Str::uuid();

            $svc->attest(self::DOMAIN, $brokerServerId);

            $this->assertContains($identity->publicKey(), $svc->authorityKeysFor(self::DOMAIN),
                'our attestation surfaces as an authority key for the domain');
            $this->assertContains($brokerServerId, $svc->brokersFor(self::DOMAIN),
                'the attested broker is routable for the domain');

            $svc->revoke(self::DOMAIN, $brokerServerId);
            $this->assertNotContains($identity->publicKey(), $svc->authorityKeysFor(self::DOMAIN),
                'revocation removes the authority key (fail-closed at read)');
        });
    }

    public function test_a_gossiped_fact_is_verified_against_the_authoritys_pinned_key(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $svc = app(BrokerAuthorizationService::class);

            // A peer authority with a real keypair, pinned as a FederationPeer.
            $keypair = sodium_crypto_sign_keypair();
            $authPub = sodium_bin2base64(sodium_crypto_sign_publickey($keypair), SODIUM_BASE64_VARIANT_ORIGINAL);
            $authSecret = sodium_crypto_sign_secretkey($keypair);
            $authServerId = (string) Str::uuid();
            $brokerServerId = (string) Str::uuid();
            $from = $this->peer($authServerId, $authPub);

            $issuedAt = now()->getTimestamp();
            $sign = fn (string $msg) => sodium_bin2base64(sodium_crypto_sign_detached($msg, $authSecret), SODIUM_BASE64_VARIANT_ORIGINAL);
            $goodSig = $sign($svc->canonicalFact(self::DOMAIN, $brokerServerId, $authServerId, $issuedAt));

            $fact = [
                'domain' => self::DOMAIN, 'broker_server_id' => $brokerServerId,
                'authority_server_id' => $authServerId, 'authority_pubkey' => $authPub,
                'signature' => $goodSig, 'issued_at' => $issuedAt,
            ];

            // 1) Valid fact ⇒ accepted for DISCOVERY (brokersFor), but NEVER for TRUST (authorityKeysFor).
            // A gossiped peer self-attestation must not enter the cert-trust list — gossip distributes
            // trust, it never bootstraps it. Otherwise any peer could self-authorize and forge cert grants.
            $this->assertSame(1, $svc->ingest([$fact], $from));
            $this->assertContains($brokerServerId, $svc->brokersFor(self::DOMAIN), 'gossip feeds discovery');
            $this->assertNotContains($authPub, $svc->authorityKeysFor(self::DOMAIN), 'gossip never feeds the cert-trust list (no self-authorization)');

            // 2) Tampered signature ⇒ dropped.
            $this->assertSame(0, $svc->ingest([array_merge($fact, ['signature' => $goodSig, 'broker_server_id' => (string) Str::uuid()])], $from),
                'a signature over a different broker_server_id does not verify');

            // 3) Pubkey-mismatch (claims a key other than the pinned one) ⇒ dropped.
            $otherPub = sodium_bin2base64(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()), SODIUM_BASE64_VARIANT_ORIGINAL);
            $this->assertSame(0, $svc->ingest([array_merge($fact, ['authority_pubkey' => $otherPub])], $from),
                'the claimed authority_pubkey must equal the authority\'s pinned key');

            // 4) Unknown authority (not pinned, not the sender) ⇒ dropped.
            $strangerServer = (string) Str::uuid();
            $this->assertSame(0, $svc->ingest([array_merge($fact, ['authority_server_id' => $strangerServer])], $from),
                'an authority with no pinned key has no standing');
        });
    }

    public function test_a_peer_cannot_self_authorize_into_the_cert_trust_list(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $svc = app(BrokerAuthorizationService::class);

            // WE legitimately attest ourselves (we hold the domain) — our key is a rooted authority.
            $svc->attest(self::DOMAIN, $identity->serverId());

            // A malicious peer gossips a SELF-attestation: authority_server_id == its own server, signed
            // with its own key. It verifies (self-signed) and is stored for discovery — but it must NEVER
            // enter the cert-trust list (the audit's domain-takeover vector).
            $kp = sodium_crypto_sign_keypair();
            $evilPub = sodium_bin2base64(sodium_crypto_sign_publickey($kp), SODIUM_BASE64_VARIANT_ORIGINAL);
            $evilSecret = sodium_crypto_sign_secretkey($kp);
            $evilServer = (string) Str::uuid();
            $from = $this->peer($evilServer, $evilPub);
            $issuedAt = now()->getTimestamp();
            $evilFact = [
                'domain' => self::DOMAIN, 'broker_server_id' => $evilServer,
                'authority_server_id' => $evilServer, 'authority_pubkey' => $evilPub,
                'signature' => sodium_bin2base64(sodium_crypto_sign_detached(
                    $svc->canonicalFact(self::DOMAIN, $evilServer, $evilServer, $issuedAt), $evilSecret
                ), SODIUM_BASE64_VARIANT_ORIGINAL),
                'issued_at' => $issuedAt,
            ];
            $this->assertSame(1, $svc->ingest([$evilFact], $from), 'the self-signed fact verifies + stores (discovery)');

            $keys = $svc->authorityKeysFor(self::DOMAIN);
            $this->assertContains($identity->publicKey(), $keys, 'our own rooted attestation IS a trusted authority key');
            $this->assertNotContains($evilPub, $keys, 'the peer self-attestation is NOT — no self-authorization, no domain takeover');
        });
    }

    private function peer(string $serverId, string $pubKey): FederationPeer
    {
        return FederationPeer::create([
            'server_id' => $serverId,
            'name' => 'Authority '.Str::random(5),
            'url' => 'http://auth-'.Str::lower(Str::random(8)).'.invalid',
            'public_key' => $pubKey,
            'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
            'trust_established_at' => now(),
        ]);
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
