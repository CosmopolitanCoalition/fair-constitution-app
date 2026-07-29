<?php

namespace App\Services\Federation;

use App\Models\FederationPeer;
use App\Services\AuditService;
use RuntimeException;
use App\Support\InstanceClass;

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
        private readonly TransportService $transports,
        private readonly MultiplexClient $multiplex,
        private readonly CapabilityService $capabilities,
        private readonly BrokerAuthorizationService $brokerAuth,
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

        // Phase O (operator ruling 2026-07-25) — CLASS-SCOPED FEDERATION.
        // A demo instance federates, but only with other demo instances: "it's
        // gonna be its own federation." The rule is SYMMETRIC — a production
        // instance refuses a demo peer and a demo refuses a production peer — so
        // unconsented synthetic records can never cross into a consent-bearing
        // mesh under Full Faith & Credit.
        //
        // Enforced at BOTH ends of the handshake because a remote instance cannot
        // see our local `instance_class` column: the class rides the signed
        // payload and is checked on ingest. A peer advertising nothing is read as
        // production — the old, consent-bearing default, which is the fail-closed
        // direction for a demo.
        $this->assertSameClass(InstanceClass::normalize($remote['instance_class'] ?? null), $url);

        $peer = FederationPeer::query()->where('server_id', $serverId)->first()
            ?? new FederationPeer(['server_id' => $serverId, 'status' => FederationPeer::STATUS_DISCOVERED]);

        $peer->fill([
            'name' => $remote['name'] ?? null,
            'url' => $url,
            'public_key' => $publicKey,
            'metadata' => [
                'schema_version' => $remote['schema_version'] ?? null,
                'matrix_server_name' => $remote['matrix_server_name'] ?? ($peer->metadata['matrix_server_name'] ?? null),
                'instance_class' => InstanceClass::normalize($remote['instance_class'] ?? null),
            ],
            'constitutional_version' => $remote['constitutional_version'] ?? null,
            'app_release' => $remote['app_release'] ?? null,
        ]);
        $peer->status ??= FederationPeer::STATUS_DISCOVERED;
        $peer->save();

        // Learn every channel the peer advertises (G8b) — the multiplex ladder's
        // primary source. Pre-G8b peers advertise no transports → ladder = legacy url.
        $this->transports->recordPeerTransports($serverId, (array) ($remote['transports'] ?? []));
        // Mesh Roles ★4 — learn the peer's capability manifest alongside its transports.
        $this->capabilities->recordPeerCapabilities($serverId, (array) ($remote['capabilities'] ?? []));
        // Mesh Roles ★8/A1 — learn the peer's broker-routing facts (each verified against its authority's key).
        $this->brokerAuth->ingest((array) ($remote['broker_authorizations'] ?? []), $peer);

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
        // The multiplex resolves the ladder by server_id, so the peer must be discovered
        // first (discover() pins the remote server_id + learns its transports). Guard with
        // a clear message rather than letting an empty ladder surface as NoSurvivingTransport.
        if ((string) $peer->server_id === '') {
            throw new RuntimeException('Cannot handshake a peer with no server_id — discover it first.');
        }

        $payload = $this->identity->handshakePayload();
        $payload['url'] = config('cga.federation_self_url');
        $payload['transports'] = $this->transports->selfEndpoints();
        $payload['capabilities'] = $this->capabilities->selfCapabilities(); // ★4 — advertise our role set, signed with the payload
        $payload['broker_authorizations'] = $this->brokerAuth->wire(); // ★8/A1 — advertise our broker-routing facts

        // Handshake over the multiplex ladder (G8b) — the peer's transports learned at
        // discovery are already in the ladder, so a handshake survives a down clearnet.
        $response = $this->multiplex->reach((string) $peer->server_id, 'POST', '/api/federation/handshake', $payload);

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
            'constitutional_version' => $remote['constitutional_version'] ?? $peer->constitutional_version,
            'app_release' => $remote['app_release'] ?? $peer->app_release,
        ]);
        $peer->save();

        // Learn the peer's full transport set + capability manifest from its handshake response (G8b/★4).
        $this->transports->recordPeerTransports($remoteServerId, (array) ($remote['transports'] ?? []));
        $this->capabilities->recordPeerCapabilities($remoteServerId, (array) ($remote['capabilities'] ?? []));
        $this->brokerAuth->ingest((array) ($remote['broker_authorizations'] ?? []), $peer); // ★8/A1

        $this->audit->append('federation', 'peer.trust_established',
            ['peer_server_id' => $remoteServerId, 'url' => $peer->url, 'direction' => 'initiated'], 'WF-JUR-06');

        return $peer;
    }

    /**
     * Refuse a cross-class peering. See the CLASS-SCOPED FEDERATION note in
     * discover(). Kept as one method so both ends of the handshake enforce
     * byte-identical semantics.
     */
    private function assertSameClass(string $peerClass, string $where): void
    {
        $ourClass = InstanceClass::current();

        if ($peerClass === $ourClass) {
            return;
        }

        throw new RuntimeException(
            "Refusing to peer across instance classes: this instance is '{$ourClass}' but "
            ."{$where} advertises '{$peerClass}'. A demo federates only with demos, and a "
            .'real instance only with real instances.'
        );
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

        // The same class rule, on the receiving side (see discover()).
        $this->assertSameClass(
            InstanceClass::normalize($payload['instance_class'] ?? null),
            (string) ($payload['url'] ?? 'the calling peer')
        );

        $this->upsertTrustedPeer($serverId, $publicKey, [
            'name' => $payload['name'] ?? null,
            'url' => $payload['url'] ?? null,
            'schema_version' => $payload['schema_version'] ?? null,
            'constitutional_version' => $payload['constitutional_version'] ?? null,
            'app_release' => $payload['app_release'] ?? null,
            'matrix_server_name' => $payload['matrix_server_name'] ?? null,
            'instance_class' => InstanceClass::normalize($payload['instance_class'] ?? null),
        ], FederationPeer::RELATION_SOVEREIGN, 'received');

        // Learn the introducing peer's transports (G8b), and advertise ours back so
        // the initiator's ladder is populated symmetrically.
        $this->transports->recordPeerTransports($serverId, (array) ($payload['transports'] ?? []));
        $this->capabilities->recordPeerCapabilities($serverId, (array) ($payload['capabilities'] ?? []));
        $fromPeer = FederationPeer::query()->where('server_id', $serverId)->whereNull('deleted_at')->first();
        if ($fromPeer !== null) {
            $this->brokerAuth->ingest((array) ($payload['broker_authorizations'] ?? []), $fromPeer); // ★8/A1
        }

        return $this->identity->handshakePayload() + [
            'url' => config('cga.federation_self_url'),
            'transports' => $this->transports->selfEndpoints(),
            'capabilities' => $this->capabilities->selfCapabilities(), // ★4 — advertise our role set back, symmetrically
            'broker_authorizations' => $this->brokerAuth->wire(), // ★8/A1 — advertise our broker-routing facts back
        ];
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
            'metadata' => [
                'schema_version' => $attrs['schema_version'] ?? null,
                // Preserve a previously-learned Matrix domain when this upsert doesn't carry one.
                'matrix_server_name' => $attrs['matrix_server_name'] ?? ($peer->metadata['matrix_server_name'] ?? null),
                // The peer's DECLARED class (class-scoped federation, ruling
                // 2026-07-25). receiveHandshake() validates and passes it;
                // before 2026-07-28 this fill silently DROPPED it, so inbound
                // rows never recorded demo-ness and the dev-time rail
                // (ruling §10 item 4: a demo mesh may time-travel) could
                // never open. Preserved across upserts that carry none — the
                // adoption exchange sends no class — and absent still reads
                // as production downstream: fail closed.
                'instance_class' => $attrs['instance_class'] ?? ($peer->metadata['instance_class'] ?? null),
            ],
            // G-VER — pin the peer's tracked versions (gate counted sync, provenance).
            'constitutional_version' => $attrs['constitutional_version'] ?? $peer->constitutional_version,
            'app_release' => $attrs['app_release'] ?? $peer->app_release,
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
