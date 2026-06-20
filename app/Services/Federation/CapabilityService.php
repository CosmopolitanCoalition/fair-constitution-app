<?php

namespace App\Services\Federation;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\InstanceCapability;

/**
 * The capability manifest registry (Mesh Roles & Channels of Trust ★3) — the SIBLING of TransportService.
 * Tracks the capability channels this instance (and, when learned, its peers) host. Our own ENABLED
 * channels are the manifest we advertise on the handshake; a peer's are learned the same way transports
 * are. Pure registry — it grants nothing itself. THE LOAD-BEARING RULE: a GOVERNED channel can only be
 * self-enabled with a verified, unexpired grant (grantSelf), never by registerSelf — so we never advertise
 * a governed role the mesh hasn't approved. Self-asserted channels need no grant.
 */
class CapabilityService
{
    public function __construct(private readonly InstanceIdentityService $identity) {}

    /** Enable one of OUR SELF-ASSERTED channels (mesh.member, mirror, etl). Refuses governed channels. */
    public function registerSelf(string $capability, int $priority = 100): InstanceCapability
    {
        if (! in_array($capability, InstanceCapability::CHANNELS, true)) {
            throw new ConstitutionalViolation("Unknown capability channel [{$capability}].", 'Mesh Roles & Channels of Trust');
        }
        if (InstanceCapability::isGoverned($capability)) {
            throw new ConstitutionalViolation(
                "[{$capability}] is a GOVERNED channel — it is enabled by a grant from the dual-meter consent, never self-asserted.",
                'Mesh Roles & Channels of Trust · decision C'
            );
        }

        return InstanceCapability::query()->updateOrCreate(
            ['server_id' => $this->identity->serverId(), 'capability' => $capability],
            ['is_self' => true, 'enabled' => true, 'priority' => $priority],
        );
    }

    /** Enable one of OUR GOVERNED channels WITH its grant receipt (called by MeshRoleGrantService::ratify). */
    public function grantSelf(string $capability, string $grantedByServerId, string $grantSignature, ?int $grantExpiresAt, int $priority = 100): InstanceCapability
    {
        if (! InstanceCapability::isGoverned($capability)) {
            throw new ConstitutionalViolation("[{$capability}] is not a governed channel.", 'Mesh Roles & Channels of Trust');
        }

        return InstanceCapability::query()->updateOrCreate(
            ['server_id' => $this->identity->serverId(), 'capability' => $capability],
            [
                'is_self' => true, 'enabled' => true, 'priority' => $priority,
                'granted_by_server_id' => $grantedByServerId,
                'grant_signature' => $grantSignature,
                'grant_expires_at' => $grantExpiresAt !== null ? \Illuminate\Support\Carbon::createFromTimestamp($grantExpiresAt) : null,
            ],
        );
    }

    /** Disable one of OUR channels (stops advertising + offering it). Always unilateral (decision C/§3.3). */
    public function disableSelf(string $capability): bool
    {
        return (bool) InstanceCapability::query()
            ->where('server_id', $this->identity->serverId())
            ->where('capability', $capability)
            ->update(['enabled' => false]);
    }

    /**
     * OUR enabled channels — the manifest the handshake advertises. Governed channels carry their grant
     * receipt so a peer can verify the role is approved, not merely claimed.
     *
     * @return list<array{capability:string,priority:int,granted_by_server_id:?string,grant_signature:?string,grant_expires_at:?int}>
     */
    public function selfCapabilities(): array
    {
        return InstanceCapability::query()
            ->where('server_id', $this->identity->serverId())
            ->where('enabled', true)
            ->orderByDesc('priority')
            ->get()
            ->map(fn (InstanceCapability $c) => [
                'capability' => (string) $c->capability,
                'priority' => (int) $c->priority,
                'granted_by_server_id' => $c->granted_by_server_id !== null ? (string) $c->granted_by_server_id : null,
                'grant_signature' => $c->grant_signature !== null ? (string) $c->grant_signature : null,
                'grant_expires_at' => $c->grant_expires_at?->getTimestamp(),
            ])->all();
    }

    /**
     * Persist the capabilities a PEER advertised (the manifest learn step) — sibling of
     * recordPeerTransports. Unknown labels skipped (defense in depth; the DB CHECK rejects them anyway),
     * idempotent per (server, capability), latest-advert-wins. We record the CLAIM + its grant receipt; a
     * consumer verifies the grant before trusting a governed channel (advertised-claim vs governed-role).
     *
     * @param  list<array<string,mixed>>  $capabilities
     */
    public function recordPeerCapabilities(string $serverId, array $capabilities): void
    {
        foreach (array_values($capabilities) as $i => $adv) {
            $capability = (string) ($adv['capability'] ?? '');
            if (! in_array($capability, InstanceCapability::CHANNELS, true)) {
                continue;
            }
            $expires = $adv['grant_expires_at'] ?? null;

            InstanceCapability::query()->updateOrCreate(
                ['server_id' => $serverId, 'capability' => $capability],
                [
                    'is_self' => false,
                    'enabled' => true, // the peer advertises it as held; trust is decided at use-time via the grant
                    'priority' => (int) ($adv['priority'] ?? (100 - $i)),
                    'granted_by_server_id' => isset($adv['granted_by_server_id']) ? (string) $adv['granted_by_server_id'] : null,
                    'grant_signature' => isset($adv['grant_signature']) ? (string) $adv['grant_signature'] : null,
                    'grant_expires_at' => is_int($expires) ? \Illuminate\Support\Carbon::createFromTimestamp($expires) : null,
                ],
            );
        }
    }

    /** @return list<string> server_ids that hold this ENABLED capability (broker/service discovery). */
    public function holdersOf(string $capability): array
    {
        return InstanceCapability::query()
            ->where('capability', $capability)
            ->where('enabled', true)
            ->orderByDesc('priority')
            ->pluck('server_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function holds(string $serverId, string $capability): bool
    {
        return InstanceCapability::query()
            ->where('server_id', $serverId)
            ->where('capability', $capability)
            ->where('enabled', true)
            ->exists();
    }
}
