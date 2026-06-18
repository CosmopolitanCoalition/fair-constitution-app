<?php

namespace App\Services\Federation;

use App\Models\DirectoryEntry;
use App\Models\FederationPeer;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * The federation DIRECTORY (Phase G, G9) — an advisory, signed, replicable
 * `jurisdiction → best endpoints` lookup.
 *
 * It answers ONE question: "where might I reach the instance that serves
 * jurisdiction X?" — so a write the local instance does not own can be FORWARDED
 * to it (G4). It is deliberately powerless: it NEVER decides who is authoritative
 * (that is the authoritative-server axis, read by AuthorityResolver), it
 * never gates a filing, and a stale or hostile entry can at worst send a write to
 * the wrong endpoint — where it is rejected, because authority is checked there.
 *
 * Each entry is signed by the SERVER it names, so a relayed copy is
 * self-authenticating and a tampered one is rejected on ingest. The feed
 * replicates like any signed record: we publish OUR entries, ingest peers' signed
 * entries (tagged with who relayed them), and resolve by priority + freshness.
 */
class DirectoryService
{
    public function __construct(
        private readonly InstanceIdentityService $identity,
        private readonly AuditService $audit,
    ) {}

    /**
     * Publish (or refresh) OUR directory entry for a jurisdiction we serve.
     *
     * @param  list<array{transport:string,url:string}>  $endpoints
     */
    public function publish(string $jurisdictionId, array $endpoints, int $priority = 100): DirectoryEntry
    {
        $entry = DirectoryEntry::query()->firstOrNew([
            'jurisdiction_id' => $jurisdictionId,
            'server_id' => $this->identity->serverId(),
            'source_server_id' => null,
        ]);

        $entry->fill([
            'endpoints' => array_values($endpoints),
            'priority' => $priority,
            'published_at' => now(),
        ]);
        $entry->signature = $this->identity->sign($this->canonical($entry));
        $entry->save();

        $this->audit->append('directory', 'directory.published', [
            'jurisdiction_id' => $jurisdictionId,
            'endpoint_count' => count($endpoints),
        ], 'WF-JUR-06', null, $jurisdictionId);

        return $entry;
    }

    /**
     * Ingest a peer-relayed signed entry. Verified against the NAMED server's pinned
     * key (not the relayer's) — so a relay cannot forge an entry for another server.
     * Returns null (silently ignored) on an unknown publisher or a bad signature.
     *
     * @param  array<string,mixed>  $wire
     */
    public function ingest(array $wire, FederationPeer $from): ?DirectoryEntry
    {
        $publisherServerId = (string) ($wire['server_id'] ?? '');
        $publisherKey = $this->publisherKey($publisherServerId, $from);

        if ($publisherServerId === '' || $publisherKey === null) {
            return null; // unknown publisher — we hold no key to authenticate it
        }

        $candidate = new DirectoryEntry([
            'jurisdiction_id' => (string) ($wire['jurisdiction_id'] ?? ''),
            'server_id' => $publisherServerId,
            'endpoints' => array_values((array) ($wire['endpoints'] ?? [])),
            'priority' => (int) ($wire['priority'] ?? 100),
            'published_at' => isset($wire['published_at'])
                ? \Carbon\CarbonImmutable::createFromTimestamp((int) $wire['published_at'])
                : now(),
        ]);

        if (! InstanceIdentityService::verify($publisherKey, $this->canonical($candidate), (string) ($wire['signature'] ?? ''))) {
            return null; // tampered or not actually signed by the named server
        }

        $entry = DirectoryEntry::query()->firstOrNew([
            'jurisdiction_id' => $candidate->jurisdiction_id,
            'server_id' => $candidate->server_id,
            'source_server_id' => (string) $from->server_id,
        ]);
        $entry->fill([
            'endpoints' => $candidate->endpoints,
            'priority' => $candidate->priority,
            'published_at' => $candidate->published_at,
        ]);
        $entry->signature = (string) $wire['signature'];
        $entry->save();

        return $entry;
    }

    /**
     * The advisory endpoints for a jurisdiction, best first (priority then
     * freshness). NEVER consulted to decide authority — only to find a route.
     *
     * @return list<array{transport:string,url:string}>
     */
    public function resolve(string $jurisdictionId): array
    {
        return DirectoryEntry::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->orderByDesc('priority')
            ->orderByDesc('published_at')
            ->get()
            ->reject(fn (DirectoryEntry $e) => $e->isExpired())
            ->flatMap(fn (DirectoryEntry $e) => $e->endpoints ?? [])
            ->values()
            ->all();
    }

    /**
     * The advisory endpoints a NAMED server is reachable at, best first (priority
     * then freshness, expired entries dropped). The multiplex ladder's directory
     * source (Phase G, G8b): "where might I reach server S?" — independent of which
     * jurisdiction it serves. Still powerless — a route hint, never authority. Each
     * endpoint carries its entry's priority so the ladder can rank across sources.
     *
     * @return list<array{transport:string,url:string,priority:int}>
     */
    public function endpointsForServer(string $serverId): array
    {
        return DirectoryEntry::query()
            ->where('server_id', $serverId)
            ->orderByDesc('priority')
            ->orderByDesc('published_at')
            ->get()
            ->reject(fn (DirectoryEntry $e) => $e->isExpired())
            ->flatMap(fn (DirectoryEntry $e) => collect($e->endpoints ?? [])->map(fn ($ep) => [
                'transport' => (string) ($ep['transport'] ?? ''),
                'url' => (string) ($ep['url'] ?? ''),
                'priority' => (int) $e->priority,
            ]))
            ->filter(fn (array $ep) => $ep['transport'] !== '' && $ep['url'] !== '')
            ->values()
            ->all();
    }

    /** The byte-stable canonical the publisher signs and a verifier reconstructs. */
    public function canonical(DirectoryEntry $entry): string
    {
        return AuditService::canonicalJson([
            'jurisdiction_id' => (string) $entry->jurisdiction_id,
            'server_id' => (string) $entry->server_id,
            'endpoints' => array_values((array) $entry->endpoints),
            'priority' => (int) $entry->priority,
            'published_at' => (int) ($entry->published_at?->getTimestamp() ?? 0),
        ]);
    }

    /** The wire form for relaying our entry to a peer. */
    public function wire(DirectoryEntry $entry): array
    {
        return [
            'jurisdiction_id' => (string) $entry->jurisdiction_id,
            'server_id' => (string) $entry->server_id,
            'endpoints' => array_values((array) $entry->endpoints),
            'priority' => (int) $entry->priority,
            'published_at' => (int) ($entry->published_at?->getTimestamp() ?? 0),
            'signature' => (string) $entry->signature,
        ];
    }

    private function publisherKey(string $publisherServerId, FederationPeer $from): ?string
    {
        if ($publisherServerId === $this->identity->serverId()) {
            return $this->identity->publicKey();
        }
        if ($publisherServerId === (string) $from->server_id && $from->public_key !== null) {
            return (string) $from->public_key;
        }

        $peer = FederationPeer::query()->where('server_id', $publisherServerId)->first();

        return $peer?->public_key !== null ? (string) $peer->public_key : null;
    }
}
