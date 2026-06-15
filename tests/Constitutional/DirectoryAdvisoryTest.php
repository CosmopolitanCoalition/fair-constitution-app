<?php

namespace Tests\Constitutional;

use App\Models\DirectoryEntry;
use App\Models\FederationPeer;
use App\Services\Federation\DirectoryService;
use App\Services\Federation\InstanceIdentityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G9 directory). The directory is ADVISORY routing
 * info, signed by the server it names, and holds NO authority. The pins:
 *  1. we publish a signed entry that verifies against our key;
 *  2. resolve returns endpoints best-first (priority then freshness);
 *  3. a peer's signed entry is ingested (verified against the NAMED server's key,
 *     not the relayer's) and a TAMPERED or unknown-publisher entry is rejected;
 *  4. the directory never touches authority (it cannot decide who is authoritative).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class DirectoryAdvisoryTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_directory';

    private const VARIANT = SODIUM_BASE64_VARIANT_ORIGINAL;

    public function test_a_published_entry_is_signed_and_verifies(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $svc = app(DirectoryService::class);
            $jid = (string) Str::uuid();

            $entry = $svc->publish($jid, [['transport' => 'https', 'url' => 'https://us.test']], 100);

            $this->assertSame($identity->serverId(), (string) $entry->server_id);
            $this->assertNull($entry->source_server_id, 'our own entry has no relay source');
            $this->assertTrue(
                InstanceIdentityService::verify($identity->publicKey(), $svc->canonical($entry), (string) $entry->signature),
                'the published entry verifies against our key'
            );
        });
    }

    public function test_resolve_returns_endpoints_best_first(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $svc = app(DirectoryService::class);
            $jid = (string) Str::uuid();

            $svc->publish($jid, [['transport' => 'https', 'url' => 'https://low.test']], 10);
            // A higher-priority relayed entry from a peer.
            [$peer] = $this->signedPeerEntry($jid, [['transport' => 'tailnet', 'url' => 'http://hi.tailnet']], 500);

            $endpoints = $svc->resolve($jid);

            $this->assertNotEmpty($endpoints);
            $this->assertSame('http://hi.tailnet', $endpoints[0]['url'], 'higher priority resolves first');
        });
    }

    public function test_a_peer_signed_entry_is_ingested_and_tampering_is_rejected(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $svc = app(DirectoryService::class);
            $jid = (string) Str::uuid();

            [$peer, $wire] = $this->signedPeerWire($jid, [['transport' => 'https', 'url' => 'https://peer.test']], 200);

            $stored = $svc->ingest($wire, $peer);
            $this->assertNotNull($stored, 'a validly-signed peer entry is ingested');
            $this->assertSame((string) $peer->server_id, (string) $stored->source_server_id, 'tagged with the relay source');

            // Tamper with the endpoints AFTER signing → rejected.
            $tampered = $wire;
            $tampered['endpoints'] = [['transport' => 'https', 'url' => 'https://attacker.test']];
            $this->assertNull($svc->ingest($tampered, $peer), 'a tampered entry is rejected');

            // An unknown publisher (no key to authenticate) → rejected.
            $orphan = $wire;
            $orphan['server_id'] = (string) Str::uuid();
            $this->assertNull($svc->ingest($orphan, $peer), 'an unknown publisher is rejected');
        });
    }

    public function test_the_directory_holds_no_authority(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Federation/DirectoryService.php'));

        $this->assertStringNotContainsString('authoritative_server_id', $src,
            'the directory is advisory routing — it must never read or write authority');
    }

    /** A peer that publishes a signed entry; returns [peer] (the entry is stored). */
    private function signedPeerEntry(string $jid, array $endpoints, int $priority): array
    {
        [$peer, $wire] = $this->signedPeerWire($jid, $endpoints, $priority);
        app(DirectoryService::class)->ingest($wire, $peer);

        return [$peer];
    }

    /** Build a peer whose key signs a directory entry, plus the wire form. */
    private function signedPeerWire(string $jid, array $endpoints, int $priority): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $pub = sodium_bin2base64(sodium_crypto_sign_publickey($keypair), self::VARIANT);
        $sec = sodium_crypto_sign_secretkey($keypair);
        $serverId = (string) Str::uuid();

        $peer = FederationPeer::create([
            'server_id' => $serverId,
            'name' => 'directory-peer',
            'url' => 'https://peer.test',
            'public_key' => $pub,
            'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
            'relation' => FederationPeer::RELATION_SOVEREIGN,
        ]);

        $publishedAt = CarbonImmutable::now();
        $entry = new DirectoryEntry([
            'jurisdiction_id' => $jid,
            'server_id' => $serverId,
            'endpoints' => array_values($endpoints),
            'priority' => $priority,
            'published_at' => $publishedAt,
        ]);

        $signature = sodium_bin2base64(
            sodium_crypto_sign_detached(app(DirectoryService::class)->canonical($entry), $sec),
            self::VARIANT
        );

        $wire = [
            'jurisdiction_id' => $jid,
            'server_id' => $serverId,
            'endpoints' => array_values($endpoints),
            'priority' => $priority,
            'published_at' => $publishedAt->getTimestamp(),
            'signature' => $signature,
        ];

        return [$peer, $wire];
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
