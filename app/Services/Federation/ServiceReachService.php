<?php

namespace App\Services\Federation;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\FederationPeer;

/**
 * Mesh Roles ★21 (Phase 5 — the mixed environment) — the GOVERNED-LIVE-SERVICE reach seam: when this node
 * does NOT host a live service (Matrix homeserver / voice SFU), resolve the best reachable mesh peer that
 * does, so a LIGHT node's players reach a CAPABLE peer ("a light node reaches across to a capable peer").
 *
 * This is DELIBERATELY a separate seam from the copy-channel/geodata reach (the ★22 307-to-bytes path),
 * which refuses governed channels for copy integrity. Live-service reach is a different SHAPE:
 *   • it returns a POINTER to a live service (a peer's homeserver / SFU), it NEVER copies bytes;
 *   • it is a READ — it dials nothing here, writes nothing, and NEVER mutates `authoritative_server_id`
 *     (reaching a peer's live service is not an authority transfer);
 *   • it accepts ONLY the live-service channels (matrix.homeserver / voice.sfu) — every other channel
 *     (the copy channels AND the non-live governed channels like broker.dns / authority.grant) is refused,
 *     so the copy-channel governed refusal is preserved, not weakened.
 * Safe degrade: no reachable holder ⇒ NoReachableHolder (the caller drops the feature), never a hard fail.
 */
class ServiceReachService
{
    /** The only channels that "reach across" a light node to a capable peer (the mixed environment). */
    public const LIVE_SERVICE_CHANNELS = ['matrix.homeserver', 'voice.sfu'];

    public function __construct(
        private readonly CapabilityService $capabilities,
        private readonly InstanceIdentityService $identity,
    ) {}

    /**
     * Resolve where a live service should be reached. Local-first (we host it ⇒ serve locally); else the
     * best reachable, trusted peer holder; else NoReachableHolder.
     *
     * @return array{local:bool,server_id:string,capability:string,service_endpoint:?string,transport:?string,url:?string,latency_ema_ms:?int,distance_km:?float}
     *
     * @throws ConstitutionalViolation when $capability is not a live-service channel
     * @throws NoReachableHolder        when nothing reachable hosts it (safe degrade)
     */
    public function reachLiveService(string $capability, ?string $nearJurisdictionId = null): array
    {
        if (! in_array($capability, self::LIVE_SERVICE_CHANNELS, true)) {
            throw new ConstitutionalViolation(
                "[{$capability}] is not a live-service channel — only ".implode(' / ', self::LIVE_SERVICE_CHANNELS)
                .' reach across a light node to a capable peer; copy channels (geodata) use the 307 redirect reach.',
                'Mesh Roles & Channels of Trust · mixed environment'
            );
        }

        // We host it → serve locally; no reach.
        if ($this->capabilities->holds($this->identity->serverId(), $capability)) {
            return $this->pointer(true, $this->identity->serverId(), $capability, $this->localEndpoint($capability));
        }

        foreach ($this->capabilities->holdersOfRanked($capability, $nearJurisdictionId) as $r) {
            if (! $r['attemptable']) {
                continue; // a tripped (open-circuit) holder is skipped — it isn't reachable right now
            }
            $peer = FederationPeer::query()->where('server_id', $r['server_id'])->whereNull('deleted_at')->first();
            if ($peer === null || ! $peer->isTrusted()) {
                continue; // only reach a known, trusted peer
            }

            return $this->pointer(
                false, $r['server_id'], $capability, $this->peerEndpoint($capability, $peer),
                $r['transport'], $r['url'], $r['latency_ema_ms'], $r['distance_km'],
            );
        }

        throw new NoReachableHolder($capability);
    }

    /** The local service endpoint when WE host the channel. */
    private function localEndpoint(string $capability): ?string
    {
        return match ($capability) {
            'matrix.homeserver' => (string) config('matrix.server_name'),
            // The EXTERNALLY-reachable SFU url (a remote client dials it), not the Docker-internal one.
            'voice.sfu' => (string) config('matrix.livekit.public_url', config('matrix.livekit.url')),
            default => null,
        };
    }

    /** A peer holder's service endpoint. */
    private function peerEndpoint(string $capability, FederationPeer $peer): ?string
    {
        return match ($capability) {
            // The peer's Matrix homeserver server_name — the player federates / is hosted there.
            'matrix.homeserver' => $peer->matrixServerName(),
            // The peer's SFU URL is advertised with the MatrixRTC foci slice (the asymmetric AV reach);
            // until then this returns null and only the chosen holder + reachability are resolved here.
            'voice.sfu' => null,
            default => null,
        };
    }

    /** @return array{local:bool,server_id:string,capability:string,service_endpoint:?string,transport:?string,url:?string,latency_ema_ms:?int,distance_km:?float} */
    private function pointer(
        bool $local,
        string $serverId,
        string $capability,
        ?string $endpoint,
        ?string $transport = null,
        ?string $url = null,
        ?int $latency = null,
        ?float $distance = null,
    ): array {
        return [
            'local' => $local,
            'server_id' => $serverId,
            'capability' => $capability,
            'service_endpoint' => $endpoint,
            'transport' => $transport,
            'url' => $url,
            'latency_ema_ms' => $latency,
            'distance_km' => $distance,
        ];
    }
}
