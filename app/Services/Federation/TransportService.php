<?php

namespace App\Services\Federation;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\FederationTransport;

/**
 * The transport registry (Phase G, G8). Tracks the channels this instance (and,
 * when learned, its peers) are reachable over. Our own enabled transports are the
 * endpoint list we publish into the G9 directory; the FederationClient SOCKS seam
 * decides how to dial each one. Pure registry — it moves no bytes itself.
 */
class TransportService
{
    public function __construct(private readonly InstanceIdentityService $identity) {}

    /** Register (or update) one of OUR reachable transports. */
    public function registerSelf(string $transport, string $address, int $priority = 100): FederationTransport
    {
        if (! in_array($transport, FederationTransport::TRANSPORTS, true)) {
            throw new ConstitutionalViolation("Unknown federation transport [{$transport}].", 'Phase G · G8');
        }

        return FederationTransport::query()->updateOrCreate(
            ['server_id' => $this->identity->serverId(), 'transport' => $transport],
            ['address' => $address, 'is_self' => true, 'priority' => $priority, 'enabled' => true],
        );
    }

    /**
     * Our enabled endpoints, best-first — the shape G9's directory publishes.
     *
     * @return list<array{transport:string,url:string}>
     */
    public function selfEndpoints(): array
    {
        return FederationTransport::query()
            ->where('server_id', $this->identity->serverId())
            ->where('enabled', true)
            ->orderByDesc('priority')
            ->get()
            ->map(fn (FederationTransport $t) => ['transport' => (string) $t->transport, 'url' => (string) $t->address])
            ->all();
    }

    /**
     * Persist the transports a PEER advertised about itself (Phase G, G8b) — the
     * ladder's primary source. Stored as that server's rows (is_self = false). An
     * unknown transport label is skipped (defense in depth; the DB CHECK would reject
     * it anyway). Priority is taken from the advert, else derived from advertised
     * order so the peer's own preference survives. Idempotent per (server, transport)
     * — the latest advert wins; a transport the peer drops simply stops being
     * refreshed (and is degraded by the circuit breaker if it later fails).
     *
     * @param  list<array{transport?:string,url?:string,priority?:int}>  $endpoints
     */
    public function recordPeerTransports(string $serverId, array $endpoints): void
    {
        foreach (array_values($endpoints) as $i => $ep) {
            $transport = (string) ($ep['transport'] ?? '');
            $address = (string) ($ep['url'] ?? '');

            if ($address === '' || ! in_array($transport, FederationTransport::TRANSPORTS, true)) {
                continue;
            }

            FederationTransport::query()->updateOrCreate(
                ['server_id' => $serverId, 'transport' => $transport],
                ['address' => $address, 'is_self' => false, 'priority' => (int) ($ep['priority'] ?? (100 - $i)), 'enabled' => true],
            );
        }
    }

    /** Disable one of OUR transports (stops advertising + dialing it). Returns rows affected. */
    public function disableSelf(string $transport): bool
    {
        return (bool) FederationTransport::query()
            ->where('server_id', $this->identity->serverId())
            ->where('transport', $transport)
            ->update(['enabled' => false]);
    }

    /**
     * The known transports for a given server, best-first.
     *
     * @return list<array{transport:string,url:string}>
     */
    public function forServer(string $serverId): array
    {
        return FederationTransport::query()
            ->where('server_id', $serverId)
            ->where('enabled', true)
            ->orderByDesc('priority')
            ->get()
            ->map(fn (FederationTransport $t) => ['transport' => (string) $t->transport, 'url' => (string) $t->address])
            ->all();
    }
}
