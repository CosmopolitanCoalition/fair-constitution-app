<?php

namespace Tests\Constitutional;

use App\Http\Controllers\Federation\BrokerCredentialShareController;
use App\Models\FederationPeer;
use App\Services\Federation\BrokerCredentialService;
use App\Services\Federation\BrokerFailoverService;
use App\Services\Federation\BrokerShareRefused;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\MultiplexClient;
use App\Services\RoleService;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — trusted-broker credential failover (Identity Broker, roles campaign Phase 4). The ONE
 * authorized exception to "the Cloudflare token never leaves the broker box": a primary broker seals its
 * per-domain credential to an EXPLICITLY-trusted failover broker. THE INVARIANTS, all fail-closed:
 *   • CONFIDENTIALITY is the seal; AUTHENTICITY is NOT (a sealed box is anonymous). So authenticity comes
 *     from the request-signed sender (the federation.signed-authenticated peer) PLUS an explicit per-domain
 *     accept opt-in — both required, and the sealed payload must name that same sender (anti-relay) and this
 *     box (anti-misdirection).
 *   • A received failover credential is never re-shared (no transitive fan-out); a local credential is never
 *     overwritten by a peer; the token never appears in status().
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class BrokerFailoverTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_brokerfailover';
    private const DOMAIN = 'worldofstatecraft.org';
    private const ZONE = 'zone-abc123';
    private const TOKEN = 'cf-secret-token-NEVER-leaks';

    public function test_happy_path_seals_to_us_and_stores_a_received_credential(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $failover = app(BrokerFailoverService::class);
            $creds = app(BrokerCredentialService::class);

            $peer = $this->trustedPeer();
            $failover->allowFrom(self::DOMAIN, (string) $peer->server_id); // receiver opt-in

            $body = $this->inboundShare(
                $identity->publicKey(),                 // sealed to US — only we can open it
                from: (string) $peer->server_id,         // names the authenticated sender
                to: $identity->serverId(),               // names this box
            );

            $result = $failover->receiveShare($peer, $body);

            $this->assertTrue($result['stored']);
            $this->assertSame(self::DOMAIN, $result['domain']);
            $this->assertSame(self::TOKEN, $creds->tokenFor(self::DOMAIN), 'the failover broker can now use the credential');
            $this->assertSame('failover', $creds->sourceOf(self::DOMAIN), 'provenance is recorded as received');
        });
    }

    public function test_refuses_without_accept_optin_and_on_relay_or_misdirection(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $failover = app(BrokerFailoverService::class);
            $creds = app(BrokerCredentialService::class);

            $peer = $this->trustedPeer();
            $myPub = $identity->publicKey();
            $myId = $identity->serverId();

            // (a) No per-domain accept opt-in → refused, nothing stored.
            $this->assertRefused(403, fn () => $failover->receiveShare(
                $peer,
                $this->inboundShare($myPub, from: (string) $peer->server_id, to: $myId),
            ));
            $this->assertFalse($creds->has(self::DOMAIN), 'a refused share stores nothing');

            // Now opt in for the remaining checks.
            $failover->allowFrom(self::DOMAIN, (string) $peer->server_id);

            // (b) ANTI-RELAY: the sealed payload names a DIFFERENT author than the authenticated sender.
            $stranger = (string) Str::uuid();
            $this->assertRefused(403, fn () => $failover->receiveShare(
                $peer,
                $this->inboundShare($myPub, from: $stranger, to: $myId),
            ));

            // (c) ANTI-MISDIRECTION: sealed to us (opens) but the payload names another recipient box.
            $this->assertRefused(422, fn () => $failover->receiveShare(
                $peer,
                $this->inboundShare($myPub, from: (string) $peer->server_id, to: (string) Str::uuid()),
            ));

            $this->assertFalse($creds->has(self::DOMAIN), 'none of the refused shares stored anything');
        });
    }

    public function test_never_overwrites_a_local_credential(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $failover = app(BrokerFailoverService::class);
            $creds = app(BrokerCredentialService::class);

            // WE are a primary for the domain (operator-entered credential).
            $creds->setCredential(self::DOMAIN, self::ZONE, 'OUR-OWN-LOCAL-TOKEN');
            $peer = $this->trustedPeer();
            $failover->allowFrom(self::DOMAIN, (string) $peer->server_id);

            $this->assertRefused(409, fn () => $failover->receiveShare(
                $peer,
                $this->inboundShare($identity->publicKey(), from: (string) $peer->server_id, to: $identity->serverId()),
            ));

            $this->assertSame('local', $creds->sourceOf(self::DOMAIN), 'our origin credential is untouched');
            $this->assertSame('OUR-OWN-LOCAL-TOKEN', $creds->tokenFor(self::DOMAIN), 'a peer can never clobber our real token');
        });
    }

    public function test_a_received_credential_is_never_reshared_and_status_is_token_free(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $failover = app(BrokerFailoverService::class);
            $creds = app(BrokerCredentialService::class);

            // We hold a credential we RECEIVED as failover.
            $creds->storeReceived(self::DOMAIN, self::ZONE, self::TOKEN, (string) Str::uuid());

            // Designating a downstream peer + attempting to re-share is refused (no transitive fan-out).
            $downstream = $this->trustedPeer();
            $failover->designateFailover(self::DOMAIN, (string) $downstream->server_id);

            $threw = false;
            try {
                $failover->shareTo(self::DOMAIN, (string) $downstream->server_id);
            } catch (\InvalidArgumentException) {
                $threw = true;
            }
            $this->assertTrue($threw, 'a received failover credential is never re-shared');

            // status() / failoverStatus() expose provenance + trust lists but NEVER the token.
            $json = json_encode($failover->failoverStatus());
            $this->assertStringNotContainsString(self::TOKEN, (string) $json, 'the token never rides status');
            $this->assertStringContainsString('failover', (string) $json, 'provenance is visible');
        });
    }

    public function test_the_outbound_share_body_carries_the_seal_but_never_the_cleartext_token(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $creds = app(BrokerCredentialService::class);

            // A real failover keypair so we can prove the seal round-trips to the recipient (and ONLY it).
            $kp = sodium_crypto_sign_keypair();
            $pub = sodium_bin2base64(sodium_crypto_sign_publickey($kp), SODIUM_BASE64_VARIANT_ORIGINAL);
            $secret = sodium_crypto_sign_secretkey($kp);
            $peer = $this->trustedPeer(pubKey: $pub);

            // Capture the body handed to the transport instead of dialling the network.
            $captured = null;
            $mux = Mockery::mock(MultiplexClient::class);
            $mux->shouldReceive('reach')->once()->andReturnUsing(function ($id, $m, $path, $payload) use (&$captured) {
                $captured = $payload;

                return new ClientResponse(new GuzzleResponse(200, [], (string) json_encode(['ok' => true])));
            });
            app()->instance(MultiplexClient::class, $mux);

            $failover = app(BrokerFailoverService::class);
            $creds->setCredential(self::DOMAIN, self::ZONE, self::TOKEN);
            $failover->designateFailover(self::DOMAIN, (string) $peer->server_id);
            $result = $failover->shareTo(self::DOMAIN, (string) $peer->server_id);

            $this->assertTrue($result['delivered']);
            $this->assertIsArray($captured);
            $this->assertArrayHasKey('sealed', $captured);
            $this->assertArrayNotHasKey('token', $captured, 'the outbound body has no token field');
            $this->assertArrayNotHasKey('zone_id', $captured, 'the outbound body has no zone field');
            $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($captured),
                'the cleartext token NEVER rides the outbound body — only the seal');

            // The seal DOES carry the token, opening only with the recipient's secret, naming both boxes.
            $inner = $this->openWith($secret, (string) $captured['sealed']);
            $this->assertSame(self::TOKEN, $inner['token']);
            $this->assertSame($identity->serverId(), $inner['from_server_id']);
            $this->assertSame((string) $peer->server_id, $inner['to_server_id']);
        });
    }

    public function test_receiver_rejects_malformed_or_untrusted_shares(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $failover = app(BrokerFailoverService::class);
            $myPub = $identity->publicKey();
            $myId = $identity->serverId();

            $peer = $this->trustedPeer();
            $failover->allowFrom(self::DOMAIN, (string) $peer->server_id);

            // Missing / empty sealed payload → 422.
            $this->assertRefused(422, fn () => $failover->receiveShare($peer, []));
            $this->assertRefused(422, fn () => $failover->receiveShare($peer, ['sealed' => '']));

            // Sealed to us + correctly addressed, but WRONG schema → 422.
            $badSchema = $this->sealedRaw($myPub, [
                'schema' => 'cga.not-a-credential-share.v1',
                'domain' => self::DOMAIN, 'zone_id' => self::ZONE, 'token' => self::TOKEN,
                'from_server_id' => (string) $peer->server_id, 'to_server_id' => $myId,
                'issued_at' => now()->getTimestamp(),
            ]);
            $this->assertRefused(422, fn () => $failover->receiveShare($peer, ['sealed' => $badSchema]));

            // A PINNED-but-not-trust-established sender → 403 at the first gate (before any opt-in check).
            $notEstablished = $this->trustedPeer(status: FederationPeer::STATUS_DISCOVERED);
            $body = $this->inboundShare($myPub, from: (string) $notEstablished->server_id, to: $myId);
            $this->assertRefused(403, fn () => $failover->receiveShare($notEstablished, $body));
        });
    }

    public function test_controller_maps_refusal_to_status_and_never_echoes_token(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $failover = app(BrokerFailoverService::class);
            $controller = app(BrokerCredentialShareController::class);

            $peer = $this->trustedPeer();
            $body = $this->inboundShare($identity->publicKey(), from: (string) $peer->server_id, to: $identity->serverId());

            // Not opted-in → 403, and the response body never carries the token.
            $resp = $controller->receive($this->signedRequest($peer, $body));
            $this->assertSame(403, $resp->getStatusCode());
            $this->assertStringNotContainsString(self::TOKEN, (string) $resp->getContent());

            // Opt in → 200 {ok, stored, domain}, still token-free.
            $failover->allowFrom(self::DOMAIN, (string) $peer->server_id);
            $resp2 = $controller->receive($this->signedRequest($peer, $body));
            $this->assertSame(200, $resp2->getStatusCode());
            $decoded = json_decode((string) $resp2->getContent(), true);
            $this->assertTrue($decoded['ok']);
            $this->assertSame(self::DOMAIN, $decoded['domain']);
            $this->assertStringNotContainsString(self::TOKEN, (string) $resp2->getContent());
        });
    }

    private function assertRefused(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected a BrokerShareRefused({$status})");
        } catch (BrokerShareRefused $e) {
            $this->assertSame($status, $e->status());
        }
    }

    /** A sealed inbound share body as an external primary would produce it. */
    private function inboundShare(string $sealToPubKey, string $from, string $to): array
    {
        return [
            'from_server_id' => $from,
            'domain' => self::DOMAIN,
            'sealed' => $this->sealedRaw($sealToPubKey, [
                'schema' => 'cga.broker-credential-share.v1',
                'domain' => self::DOMAIN,
                'zone_id' => self::ZONE,
                'token' => self::TOKEN,
                'from_server_id' => $from,
                'to_server_id' => $to,
                'issued_at' => now()->getTimestamp(),
            ]),
            'issued_at' => now()->getTimestamp(),
        ];
    }

    /** Seal an arbitrary inner payload to a public key (the raw blob, for the malformed-payload cases). */
    private function sealedRaw(string $pubKey, array $inner): string
    {
        return InstanceIdentityService::sealTo($pubKey, (string) json_encode($inner, JSON_UNESCAPED_SLASHES));
    }

    /** Open a sealed blob with a recipient's Ed25519 secret — proves the seal is addressed only to it. */
    private function openWith(string $edSecret, string $sealedB64): array
    {
        $x = sodium_crypto_sign_ed25519_sk_to_curve25519($edSecret);
        $kp = sodium_crypto_box_keypair_from_secretkey_and_publickey($x, sodium_crypto_box_publickey_from_secretkey($x));
        $plain = sodium_crypto_box_seal_open(sodium_base642bin($sealedB64, SODIUM_BASE64_VARIANT_ORIGINAL), $kp);

        return json_decode((string) $plain, true);
    }

    /** A POST request carrying $body, with the federation.signed-authenticated peer already on attributes. */
    private function signedRequest(FederationPeer $peer, array $body): Request
    {
        $req = Request::create('/api/federation/broker/credential-share', 'POST',
            content: (string) json_encode($body));
        $req->attributes->set('peer', $peer);

        return $req;
    }

    private function trustedPeer(string $status = FederationPeer::STATUS_TRUST_ESTABLISHED, ?string $pubKey = null): FederationPeer
    {
        if ($pubKey === null) {
            $kp = sodium_crypto_sign_keypair();
            $pubKey = sodium_bin2base64(sodium_crypto_sign_publickey($kp), SODIUM_BASE64_VARIANT_ORIGINAL);
        }

        return FederationPeer::create([
            'server_id' => (string) Str::uuid(),
            'name' => 'Broker '.Str::random(5),
            'url' => 'http://broker-'.Str::lower(Str::random(8)).'.invalid',
            'public_key' => $pubKey,
            'status' => $status,
            'trust_established_at' => now(),
        ]);
    }

    private function onLivePg(callable $body): void
    {
        // Isolate the broker file stores to throwaway temp paths — never touch the operator's REAL token.
        $base = sys_get_temp_dir().'/cga-brokerfailover-'.Str::random(12);
        config([
            'cga.broker.credentials_path' => $base.'-credentials.json',
            'cga.broker.failover_path' => $base.'-failover.json',
        ]);

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
            @unlink($base.'-credentials.json');
            @unlink($base.'-failover.json');
        }
    }
}
