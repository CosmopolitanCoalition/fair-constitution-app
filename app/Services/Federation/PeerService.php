<?php

namespace App\Services\Federation;

use App\Models\FederationPeer;
use App\Services\AuditService;
use RuntimeException;

/**
 * Federation peer lifecycle (Phase F, WF-JUR-06).
 *
 * Discovery (GET the peer's public identity) → handshake (mutual TOFU exchange
 * of server_id + Ed25519 public key) → trust_established. Heartbeats keep the
 * mesh liveness fresh (CLK-20). Every edge is recorded on the audit chain.
 */
class PeerService
{
    /** Allowed ESM-20 status edges. */
    private const EDGES = [
        FederationPeer::STATUS_DISCOVERED => ['handshake', 'trust_established', 'departed'],
        FederationPeer::STATUS_HANDSHAKE => ['trust_established', 'discovered', 'departed'],
        FederationPeer::STATUS_TRUST_ESTABLISHED => ['syncing', 'conflict_resolution', 'border_settled', 'merged', 'departed'],
        FederationPeer::STATUS_SYNCING => ['trust_established', 'conflict_resolution', 'departed'],
        FederationPeer::STATUS_CONFLICT_RESOLUTION => ['trust_established', 'syncing', 'departed'],
        FederationPeer::STATUS_BORDER_SETTLED => ['trust_established', 'departed'],
        FederationPeer::STATUS_MERGED => ['departed'],
        FederationPeer::STATUS_DEPARTED => [],
    ];

    public function __construct(
        private readonly InstanceIdentityService $identity,
        private readonly FederationClient $client,
        private readonly AuditService $audit,
    ) {}

    /**
     * Discover a peer by URL: read its public identity and record it. Idempotent
     * — re-discovering a known peer refreshes its key/url without downgrading
     * an already-trusted status.
     */
    public function discover(string $url): FederationPeer
    {
        $url = rtrim($url, '/');

        $response = $this->client->get($url, '/api/federation/identity');

        if (! $response->successful()) {
            throw new RuntimeException("Peer at {$url} did not return an identity (HTTP {$response->status()}).");
        }

        $remote = (array) $response->json();
        $serverId = (string) ($remote['server_id'] ?? '');
        $publicKey = (string) ($remote['public_key'] ?? '');

        if ($serverId === '' || $publicKey === '') {
            throw new RuntimeException("Peer at {$url} returned an incomplete identity.");
        }
        if ($serverId === $this->identity->serverId()) {
            throw new RuntimeException('Refusing to peer with self.');
        }

        $peer = FederationPeer::query()->where('server_id', $serverId)->first()
            ?? new FederationPeer(['server_id' => $serverId, 'status' => FederationPeer::STATUS_DISCOVERED]);

        $peer->fill([
            'name' => $remote['name'] ?? null,
            'url' => $url,
            'public_key' => $publicKey,
            'metadata' => ['schema_version' => $remote['schema_version'] ?? null],
        ]);
        $peer->status ??= FederationPeer::STATUS_DISCOVERED;
        $peer->save();

        $this->audit->append('federation', 'peer.discovered',
            ['peer_server_id' => $serverId, 'url' => $url], 'WF-JUR-06');

        return $peer;
    }

    /**
     * Initiate the handshake: present OUR identity to the peer's /handshake and
     * pin the identity it returns. Promotes the peer to trust_established.
     */
    public function initiateHandshake(FederationPeer $peer): FederationPeer
    {
        $payload = $this->identity->handshakePayload();
        $payload['url'] = config('cga.federation_self_url');

        $response = $this->client->post($peer->url, '/api/federation/handshake', $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Handshake with {$peer->url} failed (HTTP {$response->status()}).");
        }

        $remote = (array) $response->json();
        $remoteServerId = (string) ($remote['server_id'] ?? '');
        $remotePublicKey = (string) ($remote['public_key'] ?? '');

        if ($remoteServerId === '' || $remotePublicKey === '') {
            throw new RuntimeException('Handshake response was incomplete.');
        }
        if ($peer->server_id !== null && $peer->server_id !== $remoteServerId) {
            throw new RuntimeException('Handshake server_id does not match the discovered peer.');
        }

        $peer->fill([
            'server_id' => $remoteServerId,
            'public_key' => $remotePublicKey,
            'name' => $remote['name'] ?? $peer->name,
            'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
            'trust_established_at' => now(),
        ]);
        $peer->save();

        $this->audit->append('federation', 'peer.trust_established',
            ['peer_server_id' => $remoteServerId, 'url' => $peer->url, 'direction' => 'initiated'], 'WF-JUR-06');

        return $peer;
    }

    /**
     * Server side of POST /api/federation/handshake (signature already TOFU-
     * verified by the middleware). Pins the caller and returns OUR identity.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function receiveHandshake(array $payload): array
    {
        $serverId = (string) ($payload['server_id'] ?? '');
        $publicKey = (string) ($payload['public_key'] ?? '');

        if ($serverId === '' || $publicKey === '') {
            throw new RuntimeException('Handshake payload incomplete.');
        }
        if ($serverId === $this->identity->serverId()) {
            throw new RuntimeException('Refusing to peer with self.');
        }

        $this->upsertTrustedPeer($serverId, $publicKey, [
            'name' => $payload['name'] ?? null,
            'url' => $payload['url'] ?? null,
            'schema_version' => $payload['schema_version'] ?? null,
        ], FederationPeer::RELATION_SOVEREIGN, 'received');

        return $this->identity->handshakePayload() + ['url' => config('cga.federation_self_url')];
    }

    /**
     * Find-or-create a trusted peer and pin it (trust-on-first-use). The single
     * source of truth for promoting a peer to trust_established — the sovereign
     * handshake AND the mirror host/mirror edges (Phase G) all land here.
     * `relation` discriminates the edge; the default `sovereign` keeps the Phase F
     * handshake byte-identical (same row shape, same audit payload).
     *
     * @param  array<string,mixed>  $attrs  name / url / schema_version overrides
     */
    public function upsertTrustedPeer(
        string $serverId,
        string $publicKey,
        array $attrs = [],
        string $relation = FederationPeer::RELATION_SOVEREIGN,
        string $direction = 'received',
    ): FederationPeer {
        $peer = FederationPeer::query()->where('server_id', $serverId)->first()
            ?? new FederationPeer(['server_id' => $serverId]);

        $peer->fill([
            'name' => $attrs['name'] ?? $peer->name,
            'url' => $attrs['url'] ?? $peer->url ?? '',
            'public_key' => $publicKey,
            'relation' => $relation,
            'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
            'trust_established_at' => now(),
            'metadata' => ['schema_version' => $attrs['schema_version'] ?? null],
        ]);
        $peer->save();

        $this->audit->append('federation', 'peer.trust_established',
            ['peer_server_id' => $serverId, 'direction' => $direction], 'WF-JUR-06');

        return $peer;
    }

    public function recordHeartbeat(FederationPeer $peer): void
    {
        $peer->last_heartbeat_at = now();
        $peer->save();
    }

    /** Guarded ESM-20 transition; every edge is chained. */
    public function transition(FederationPeer $peer, string $toStatus): void
    {
        $from = (string) $peer->status;
        $allowed = self::EDGES[$from] ?? [];

        if (! in_array($toStatus, $allowed, true)) {
            throw new RuntimeException("Illegal peer transition {$from} → {$toStatus}.");
        }

        $peer->status = $toStatus;
        if ($toStatus === FederationPeer::STATUS_TRUST_ESTABLISHED && $peer->trust_established_at === null) {
            $peer->trust_established_at = now();
        }
        $peer->save();

        $this->audit->append('federation', 'peer.transition',
            ['peer_server_id' => $peer->server_id, 'from' => $from, 'to' => $toStatus], 'WF-JUR-06');
    }
}
