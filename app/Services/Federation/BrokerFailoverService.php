<?php

namespace App\Services\Federation;

use App\Models\FederationPeer;
use App\Services\AuditService;
use InvalidArgumentException;
use Throwable;

/**
 * Trusted-broker credential failover (Identity Broker — roles campaign Phase 4).
 *
 * THE ONE AUTHORIZED EXCEPTION to "the Cloudflare token never leaves the broker box." A primary broker may
 * OPT IN to share its per-domain Cloudflare credential, SEALED, with an EXPLICITLY-trusted failover broker,
 * so that broker can issue certs + write DNS for the domain if the primary is down. "Limited to the trusted
 * nodes in the network" (operator, 2026-06-26).
 *
 * The security model rests on a sharp distinction:
 *   • CONFIDENTIALITY comes from the seal — InstanceIdentityService::sealTo (libsodium sealed box). Only the
 *     recipient's box can open it; the token never appears in clear on the wire, in a log, in the audit, or
 *     in any prop. BUT a sealed box is ANONYMOUS — ANYONE can seal to a public key — so the seal gives
 *     confidentiality, NOT authenticity.
 *   • AUTHENTICITY + AUTHORIZATION come from TWO independent, EXPLICIT opt-ins (mutual; both sides name the
 *     other), enforced fail-closed:
 *       (1) Sender gate — shareTo only delivers to a peer the operator DESIGNATED as a failover target for
 *           the domain, and only a credential this box OWNS (source='local'); a RECEIVED failover credential
 *           is never re-shared (no transitive fan-out).
 *       (2) Receiver gate — receiveShare accepts only from a peer the operator ALLOWED for the domain, only
 *           when the request is signed by that exact pinned peer (the federation.signed middleware
 *           authenticates the request against the peer's pinned key — the AUTHENTICATED peer, never a body
 *           field, is the source of truth for "who sent this"), and only when the sealed inner payload names
 *           that same sender + this box + this domain (anti-relay / anti-confused-deputy).
 *
 * Revocation is honest: once shared, the bytes are out. undesignate/deny stop FUTURE shares; to truly
 * revoke a shared credential, ROTATE the Cloudflare token and re-share to the still-trusted failovers.
 *
 * The trust lists (accept_from / share_with) are broker-local operator config — kept, like the credential
 * token itself, in a gitignored storage/app/broker file the FF&C sync never touches. They never federate.
 */
class BrokerFailoverService
{
    private const SCHEMA = 'cga.broker-credential-share.v1';

    public function __construct(
        private readonly BrokerCredentialService $credentials,
        private readonly InstanceIdentityService $identity,
        private readonly MultiplexClient $mux,
        private readonly AuditService $audit,
    ) {}

    // ── Sender side (the PRIMARY broker that holds the token) ─────────────────────────────────────────────

    /**
     * Designate a peer as a failover target for a domain (operator opt-in). The credential is NOT sent here
     * — shareTo (or shareAll on token rotation) performs the sealed delivery. Validates the peer is a
     * trust-established peer with a pinned key, so a share can never target an unknown box.
     */
    public function designateFailover(string $domain, string $peerServerId): void
    {
        $this->requireTrustedPeer($peerServerId);
        $domain = strtolower(trim($domain));
        $this->addToList('share_with', $domain, $peerServerId);

        $this->audit->append('federation', 'broker.failover.designated', [
            'domain' => $domain,
            'peer_server_id' => $peerServerId,
        ], 'MESH-ROLES');
    }

    /** Stop sharing future credentials for a domain with a peer. Does NOT recall already-delivered bytes. */
    public function undesignateFailover(string $domain, string $peerServerId): bool
    {
        $domain = strtolower(trim($domain));
        $removed = $this->removeFromList('share_with', $domain, $peerServerId);
        if ($removed) {
            $this->audit->append('federation', 'broker.failover.undesignated', [
                'domain' => $domain,
                'peer_server_id' => $peerServerId,
            ], 'MESH-ROLES');
        }

        return $removed;
    }

    /**
     * Seal THIS box's local credential for $domain to a designated failover peer and push it over the mesh.
     * Fail-closed: refuses unless we hold a LOCAL (origin) credential for the domain AND the peer is a
     * designated failover target with a pinned key. A received (source='failover') credential is never
     * re-shared — only an origin leaves a box.
     *
     * @return array{peer_server_id:string, delivered:bool, status:int}
     */
    public function shareTo(string $domain, string $peerServerId): array
    {
        $domain = strtolower(trim($domain));

        if (! $this->credentials->has($domain)) {
            throw new InvalidArgumentException("No broker credential is stored for [{$domain}] — nothing to share.");
        }
        if ($this->credentials->sourceOf($domain) !== 'local') {
            throw new InvalidArgumentException(
                "The credential for [{$domain}] was itself received as failover — it is never re-shared (no transitive fan-out)."
            );
        }
        if (! in_array($peerServerId, $this->listFor('share_with', $domain), true)) {
            throw new InvalidArgumentException(
                "Peer [{$peerServerId}] is not a designated failover target for [{$domain}] — designate it first."
            );
        }

        $peer = $this->requireTrustedPeer($peerServerId);

        $token = (string) $this->credentials->tokenFor($domain);
        $zone = (string) $this->credentials->zoneFor($domain);
        if ($token === '' || $zone === '') {
            throw new InvalidArgumentException("The stored credential for [{$domain}] is incomplete (missing zone or token).");
        }

        $issuedAt = now()->getTimestamp();
        $payload = (string) json_encode([
            'schema' => self::SCHEMA,
            'domain' => $domain,
            'zone_id' => $zone,
            'token' => $token,
            'from_server_id' => $this->identity->serverId(),
            'to_server_id' => (string) $peer->server_id,
            'issued_at' => $issuedAt,
        ], JSON_UNESCAPED_SLASHES);

        $sealed = InstanceIdentityService::sealTo((string) $peer->public_key, $payload);
        // The token lived in $payload only to be sealed; drop both before the network call.
        unset($token, $payload);

        $resp = $this->mux->reach((string) $peer->server_id, 'POST', '/api/federation/broker/credential-share', [
            'from_server_id' => $this->identity->serverId(),
            'domain' => $domain,
            'sealed' => $sealed,
            'issued_at' => $issuedAt,
        ]);

        $this->audit->append('federation', 'broker.failover.shared', [
            'domain' => $domain,
            'peer_server_id' => (string) $peer->server_id,
            'sealed_fingerprint' => hash('sha256', $sealed), // NEVER the token
            'http_status' => $resp->status(),
        ], 'MESH-ROLES');

        return ['peer_server_id' => (string) $peer->server_id, 'delivered' => $resp->successful(), 'status' => $resp->status()];
    }

    /**
     * Re-push the domain's credential to EVERY designated failover (e.g. after a token rotation). One peer's
     * failure never aborts the rest.
     *
     * @return list<array{peer_server_id:string, delivered:bool, status:int, error:?string}>
     */
    public function shareAll(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $out = [];
        foreach ($this->listFor('share_with', $domain) as $peerServerId) {
            try {
                $out[] = $this->shareTo($domain, $peerServerId) + ['error' => null];
            } catch (Throwable $e) {
                $out[] = ['peer_server_id' => $peerServerId, 'delivered' => false, 'status' => 0, 'error' => $e->getMessage()];
            }
        }

        return $out;
    }

    // ── Receiver side (the FAILOVER broker that may hold the token if the primary is down) ────────────────

    /** Opt IN to accept failover credentials for a domain from a specific trusted peer (operator opt-in). */
    public function allowFrom(string $domain, string $peerServerId): void
    {
        $this->requireTrustedPeer($peerServerId);
        $domain = strtolower(trim($domain));
        $this->addToList('accept_from', $domain, $peerServerId);

        $this->audit->append('federation', 'broker.failover.allowed', [
            'domain' => $domain,
            'peer_server_id' => $peerServerId,
        ], 'MESH-ROLES');
    }

    /** Stop accepting failover credentials for a domain from a peer. Does NOT delete an already-stored one. */
    public function denyFrom(string $domain, string $peerServerId): bool
    {
        $domain = strtolower(trim($domain));
        $removed = $this->removeFromList('accept_from', $domain, $peerServerId);
        if ($removed) {
            $this->audit->append('federation', 'broker.failover.denied', [
                'domain' => $domain,
                'peer_server_id' => $peerServerId,
            ], 'MESH-ROLES');
        }

        return $removed;
    }

    public function acceptsFrom(string $domain, string $peerServerId): bool
    {
        return in_array($peerServerId, $this->listFor('accept_from', strtolower(trim($domain))), true);
    }

    /**
     * Receive a sealed credential share from a pinned peer. $from is the federation.signed-AUTHENTICATED
     * peer (the request signature was already verified against $from's pinned key) — it, never a body field,
     * is who sent this. Every check below is fail-closed: on ANY failure we throw BrokerShareRefused and
     * store NOTHING.
     *
     * @param  array<string,mixed>  $body
     * @return array{stored:bool, domain:string, from_server_id:string}
     */
    public function receiveShare(FederationPeer $from, array $body): array
    {
        // (1) The authenticated sender must be a trust-established peer with a pinned key.
        if ($from->status !== FederationPeer::STATUS_TRUST_ESTABLISHED || $from->public_key === null) {
            throw new BrokerShareRefused('Sender is not a trust-established peer.', 403);
        }

        // (2) Open the seal — only THIS box can. A blob sealed to anyone else (or corrupt) throws here.
        $sealed = (string) ($body['sealed'] ?? '');
        if ($sealed === '') {
            throw new BrokerShareRefused('Missing sealed payload.', 422);
        }
        try {
            $plain = $this->identity->openSealed($sealed);
        } catch (Throwable) {
            throw new BrokerShareRefused('Sealed payload is not addressed to this box, or is corrupt.', 422);
        }

        // (3) Validate the inner payload shape.
        $inner = json_decode($plain, true);
        unset($plain);
        if (! is_array($inner) || ($inner['schema'] ?? null) !== self::SCHEMA) {
            throw new BrokerShareRefused('Sealed payload is not a broker-credential share.', 422);
        }

        $domain = strtolower(trim((string) ($inner['domain'] ?? '')));
        $zone = trim((string) ($inner['zone_id'] ?? ''));
        $token = (string) ($inner['token'] ?? '');
        $innerFrom = (string) ($inner['from_server_id'] ?? '');
        $innerTo = (string) ($inner['to_server_id'] ?? '');
        unset($inner);

        // (4) The seal names THIS box as recipient (anti-misdirection — defence in depth over openSealed).
        if ($innerTo !== $this->identity->serverId()) {
            throw new BrokerShareRefused('Sealed payload is addressed to a different box.', 422);
        }

        // (5) THE ANTI-RELAY GATE: the seal must name the SAME peer that signed the request. Because sealTo
        // is anonymous, a pinned peer could otherwise relay a blob a third party authored — binding
        // authorship to the request-signed sender closes that confused-deputy path.
        if ($innerFrom !== (string) $from->server_id) {
            throw new BrokerShareRefused('Sealed payload does not name the authenticated sender.', 403);
        }

        if ($domain === '' || $zone === '' || $token === '') {
            throw new BrokerShareRefused('Sealed payload is missing the domain, zone, or token.', 422);
        }

        // (6) THE RECEIVER OPT-IN GATE (authoritative domain = the sealed inner one).
        if (! $this->acceptsFrom($domain, (string) $from->server_id)) {
            throw new BrokerShareRefused('No failover accept opt-in for this domain from this sender.', 403);
        }

        // (7) Never let a peer clobber OUR OWN origin credential — if we hold a local one, we don't need a
        // failover and must not overwrite the real token.
        if ($this->credentials->sourceOf($domain) === 'local') {
            throw new BrokerShareRefused('This box holds its own credential for the domain — refusing to overwrite it.', 409);
        }

        $this->credentials->storeReceived($domain, $zone, $token, $innerFrom);
        unset($token);

        return ['stored' => true, 'domain' => $domain, 'from_server_id' => $innerFrom];
    }

    // ── Status (UI / CLI) — never a token ─────────────────────────────────────────────────────────────────

    /**
     * @return array{
     *   credentials: list<array<string,mixed>>,
     *   accept_from: array<string,list<string>>,
     *   share_with: array<string,list<string>>
     * }
     */
    public function failoverStatus(): array
    {
        $doc = $this->readDoc();

        return [
            'credentials' => $this->credentials->status(), // already token-free
            'accept_from' => $doc['accept_from'],
            'share_with' => $doc['share_with'],
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────────────────────────────────────

    private function requireTrustedPeer(string $peerServerId): FederationPeer
    {
        $peer = FederationPeer::query()
            ->where('server_id', $peerServerId)
            ->whereNull('deleted_at')
            ->first();

        if ($peer === null || $peer->status !== FederationPeer::STATUS_TRUST_ESTABLISHED || $peer->public_key === null) {
            throw new InvalidArgumentException("No trust-established peer with a pinned key matches [{$peerServerId}].");
        }

        return $peer;
    }

    /** @return list<string> */
    private function listFor(string $bucket, string $domain): array
    {
        $doc = $this->readDoc();

        return array_values(array_map('strval', (array) ($doc[$bucket][$domain] ?? [])));
    }

    private function addToList(string $bucket, string $domain, string $peerServerId): void
    {
        $doc = $this->readDoc();
        $list = array_values(array_unique(array_merge(
            array_map('strval', (array) ($doc[$bucket][$domain] ?? [])),
            [$peerServerId],
        )));
        $doc[$bucket][$domain] = $list;
        $this->writeDoc($doc);
    }

    private function removeFromList(string $bucket, string $domain, string $peerServerId): bool
    {
        $doc = $this->readDoc();
        $existing = array_map('strval', (array) ($doc[$bucket][$domain] ?? []));
        $next = array_values(array_filter($existing, fn (string $id) => $id !== $peerServerId));
        if (count($next) === count($existing)) {
            return false;
        }
        if ($next === []) {
            unset($doc[$bucket][$domain]);
        } else {
            $doc[$bucket][$domain] = $next;
        }
        $this->writeDoc($doc);

        return true;
    }

    private function path(): string
    {
        return (string) config('cga.broker.failover_path', storage_path('app/broker/failover.json'));
    }

    /** @return array{accept_from:array<string,list<string>>, share_with:array<string,list<string>>} */
    private function readDoc(): array
    {
        $p = $this->path();
        $doc = is_file($p) ? json_decode((string) @file_get_contents($p), true) : null;
        $doc = is_array($doc) ? $doc : [];

        return [
            'accept_from' => is_array($doc['accept_from'] ?? null) ? $doc['accept_from'] : [],
            'share_with' => is_array($doc['share_with'] ?? null) ? $doc['share_with'] : [],
        ];
    }

    /** @param array{accept_from:array<string,list<string>>, share_with:array<string,list<string>>} $doc */
    private function writeDoc(array $doc): void
    {
        // Atomic write (temp-then-rename, 0600 before publish) — same discipline as the credential store:
        // no umask-readable window, no torn read for a concurrent status query.
        $path = $this->path();
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Could not create the broker failover directory.');
        }
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(6));
        file_put_contents($tmp, (string) json_encode([
            'accept_from' => $doc['accept_from'],
            'share_with' => $doc['share_with'],
        ], JSON_PRETTY_PRINT));
        @chmod($tmp, 0600);
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write the broker failover file.');
        }
    }
}
