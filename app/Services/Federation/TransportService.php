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
