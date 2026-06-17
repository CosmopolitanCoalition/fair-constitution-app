<?php

namespace Tests\Feature;

use App\Models\FederationPeer;
use App\Services\Federation\FederationClient;
use App\Services\Federation\GeodataManifestService;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Phase G (G3c — decision N3) — the GEODATA_ORIGIN signed-dataset MANIFEST channel.
 * Pins: a self-published manifest is served (signed by this origin) to a pinned
 * peer; a peer-origin-signed manifest is INGESTED only when its signature verifies
 * against the named origin's pinned key (forged → dropped, fail-closed); a mirror
 * PULL fetches + records it. The channel carries only public dataset metadata —
 * never raster bytes (Phase H) and never private data.
 *
 * Live-pg posture; a simulated origin peer (whose key the test holds) stands in for
 * a remote dataset origin.
 */
class GeodataManifestChannelTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_geodata';

    public function test_publish_serve_then_verify_ingest_and_pull(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);

            $svc = app(GeodataManifestService::class);

            // Self-publish a manifest we host (signed by THIS instance).
            $sha = str_repeat('a', 64);
            $svc->publish('worldpop-2023-100m', '2023.06.28', $sha, 'CC-BY-4.0', 123456789);

            // SERVE: a pinned peer pulls it over the signed GET endpoint.
            $peer = $this->makeTrustedPeer();
            $target = '/api/federation/geodata/manifest?dataset=worldpop-2023-100m';
            $resp = $this->call('GET', $target, server: $this->signedGet($target, (string) $peer->server_id));

            $resp->assertStatus(200)
                ->assertJsonPath('dataset', 'worldpop-2023-100m')
                ->assertJsonPath('license', 'CC-BY-4.0')
                ->assertJsonPath('origin_server_id', $identity->serverId());

            $wire = (array) $resp->json();
            $this->assertTrue(
                InstanceIdentityService::verify(
                    $identity->publicKey(),
                    $svc->canonical($wire['dataset'], $wire['version'], $wire['sha256'], $wire['license'], (int) $wire['size_bytes'], $wire['origin_server_id']),
                    $wire['signature'],
                ),
                'the served manifest is signed by the origin (this instance)',
            );

            // INGEST: a manifest from a DIFFERENT origin, signed by that origin.
            $originKeypair = sodium_crypto_sign_keypair();
            $originSecret = sodium_crypto_sign_secretkey($originKeypair);
            $originPublic = sodium_bin2base64(sodium_crypto_sign_publickey($originKeypair), SODIUM_BASE64_VARIANT_ORIGINAL);
            $originPeer = FederationPeer::create([
                'server_id'           => (string) Str::uuid(),
                'name'                => 'Geodata origin (test)',
                'url'                 => 'http://host.docker.internal:9997',
                'public_key'          => $originPublic,
                'status'              => FederationPeer::STATUS_TRUST_ESTABLISHED,
                'trust_established_at' => now(),
                'metadata'            => ['schema_version' => '1'],
            ]);

            $bSha = str_repeat('b', 64);
            $canon = $svc->canonical('geoboundaries-adm', '2024.01', $bSha, 'CC-BY-4.0', 555, (string) $originPeer->server_id);
            $sig = sodium_bin2base64(sodium_crypto_sign_detached($canon, $originSecret), SODIUM_BASE64_VARIANT_ORIGINAL);

            $goodWire = [
                'dataset' => 'geoboundaries-adm', 'version' => '2024.01', 'sha256' => $bSha,
                'license' => 'CC-BY-4.0', 'size_bytes' => 555,
                'origin_server_id' => (string) $originPeer->server_id, 'signature' => $sig,
            ];

            $rec = $svc->ingest($goodWire, $originPeer);
            $this->assertNotNull($rec, 'a validly origin-signed manifest is ingested');
            $this->assertNotNull($rec->fetched_at, 'an ingested manifest is stamped fetched');

            // A FORGED manifest (tampered after signing) is dropped, fail-closed.
            $forged = $goodWire;
            $forged['sha256'] = str_repeat('c', 64); // signature no longer matches the canonical
            $this->assertNull($svc->ingest($forged, $originPeer), 'a tampered manifest is refused');

            // PULL: the mirror-side fetch wires the signed GET into ingest.
            Http::fake(['http://host.docker.internal:9997/*' => Http::response($goodWire, 200)]);
            $pulled = $svc->pullFrom($originPeer, 'geoboundaries-adm');
            $this->assertNotNull($pulled, 'pullFrom fetches + records the manifest');
            $this->assertSame('geoboundaries-adm', $pulled->dataset);
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }

    /** @return array<string,string> */
    private function signedGet(string $target, string $serverId): array
    {
        $ts = now()->timestamp;
        $signingString = FederationClient::signingString('GET', $target, $ts, '');
        $signature = sodium_bin2base64(sodium_crypto_sign_detached($signingString, $this->peerSecret), SODIUM_BASE64_VARIANT_ORIGINAL);

        return [
            'HTTP_X_FEDERATION_SERVER_ID' => $serverId,
            'HTTP_X_FEDERATION_TIMESTAMP' => (string) $ts,
            'HTTP_X_FEDERATION_SIGNATURE' => $signature,
            'HTTP_ACCEPT'                 => 'application/json',
        ];
    }
}
