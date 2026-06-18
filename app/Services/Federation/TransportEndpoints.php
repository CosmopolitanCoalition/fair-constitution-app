<?php

namespace App\Services\Federation;

use App\Models\FederationPeer;
use App\Models\FederationTransport;
use App\Models\FederationTransportHealth;

/**
 * The multiplex failover LADDER (Phase G, G8b). Builds the ordered set of base URLs
 * the MultiplexClient should try to reach a peer, best-first. A peer is ONE identity
 * (server_id + pinned key) reachable over a SET of transports; this assembles that
 * set from three sources, dedupes it, attaches reachability health, and sorts it.
 *
 * Sources (unioned, deduped by (transport, url)):
 *   1. the peer's LEARNED transports — federation_transports rows (priority-ranked);
 *   2. the G9 DIRECTORY's advisory endpoints naming that server;
 *   3. the legacy single `federation_peers.url` (back-compat — inferred transport).
 *
 * With only an https row (or just the legacy url) the ladder has ONE rung and the
 * multiplex is byte-identical to dialing FederationClient directly — the back-compat
 * guarantee. It moves no bytes itself.
 */
class TransportEndpoints
{
    /** Transports that survive a censored uplink — sorted first under censorship_floor_first. */
    private const CENSORSHIP_RESISTANT = ['onion', 'yggdrasil'];

    public function __construct(private readonly DirectoryService $directory) {}

    /**
     * The ordered failover ladder for a peer. Each rung:
     *   transport, url, priority, circuit, health_score, latency_ema_ms, attemptable
     * `attemptable` is false ONLY for an OPEN circuit still inside its cooldown — the
     * dialer skips those on its first pass (fail fast) and falls back to them only if
     * every healthier rung fails.
     *
     * @return list<array{transport:string,url:string,priority:int,circuit:string,health_score:int,latency_ema_ms:int|null,attemptable:bool}>
     */
    public function forPeer(string $serverId): array
    {
        $merged = [];
        foreach ($this->collect($serverId) as $rung) {
            $key = $rung['transport'].'|'.$rung['url'];
            // Dedupe by (transport, url); keep the highest priority seen for the pair.
            if (! isset($merged[$key]) || $rung['priority'] > $merged[$key]['priority']) {
                $merged[$key] = $rung;
            }
        }

        $health = $this->healthFor($serverId);
        $cooldown = (int) config('cga.federation_transport_circuit_cooldown_seconds', 60);
        $cooledBefore = now()->subSeconds(max(0, $cooldown));

        $ladder = [];
        foreach ($merged as $rung) {
            $h = $health[$rung['transport'].'|'.$rung['url']] ?? null;
            $raw = $h?->circuit_state ?? FederationTransportHealth::CIRCUIT_CLOSED;
            $cooled = $h?->last_fail_at !== null && $h->last_fail_at <= $cooledBefore;

            // A persisted OPEN circuit that has cooled is a HALF_OPEN probe candidate:
            // eligible for one dial, sorted between healthy (closed) and dead (open).
            $circuit = ($raw === FederationTransportHealth::CIRCUIT_OPEN && $cooled)
                ? FederationTransportHealth::CIRCUIT_HALF_OPEN
                : $raw;

            $ladder[] = [
                'transport' => $rung['transport'],
                'url' => $rung['url'],
                'priority' => $rung['priority'],
                'circuit' => $circuit,
                'health_score' => match ($circuit) {
                    FederationTransportHealth::CIRCUIT_CLOSED => 2,
                    FederationTransportHealth::CIRCUIT_HALF_OPEN => 1,
                    default => 0,
                },
                'latency_ema_ms' => $h?->latency_ema_ms,
                // Only a still-open (not-yet-cooled) circuit is skipped on pass 1.
                'attemptable' => $circuit !== FederationTransportHealth::CIRCUIT_OPEN,
            ];
        }

        usort($ladder, fn (array $a, array $b) => $this->compare($a, $b));

        return $ladder;
    }

    /** The three raw sources, each as [{transport,url,priority}], before dedupe. */
    private function collect(string $serverId): array
    {
        $rungs = [];

        // 1. Learned transports (the registry) — read the model directly so we keep
        //    the priority value (TransportService::forServer drops it for publishing).
        foreach (FederationTransport::query()
            ->where('server_id', $serverId)
            ->where('enabled', true)
            ->get() as $t) {
            $rungs[] = ['transport' => (string) $t->transport, 'url' => (string) $t->address, 'priority' => (int) $t->priority];
        }

        // 2. The directory's advisory endpoints naming this server.
        foreach ($this->directory->endpointsForServer($serverId) as $ep) {
            $rungs[] = $ep;
        }

        // 3. Legacy single url (back-compat) — lowest priority; transport inferred.
        $peer = FederationPeer::query()->where('server_id', $serverId)->first();
        if ($peer !== null && (string) $peer->url !== '') {
            $rungs[] = ['transport' => $this->inferTransport((string) $peer->url), 'url' => (string) $peer->url, 'priority' => 0];
        }

        return $rungs;
    }

    /**
     * @return array<string,FederationTransportHealth> keyed by "transport|url"
     */
    private function healthFor(string $serverId): array
    {
        return FederationTransportHealth::query()
            ->where('server_id', $serverId)
            ->get()
            ->keyBy(fn (FederationTransportHealth $h) => $h->transport.'|'.$h->url)
            ->all();
    }

    /** Sort: censorship floor (if posture set) → health → priority → latency. */
    private function compare(array $a, array $b): int
    {
        if ((bool) config('cga.federation_censorship_floor_first', false)) {
            $fa = in_array($a['transport'], self::CENSORSHIP_RESISTANT, true) ? 0 : 1;
            $fb = in_array($b['transport'], self::CENSORSHIP_RESISTANT, true) ? 0 : 1;
            if ($fa !== $fb) {
                return $fa <=> $fb;
            }
        }

        if ($a['health_score'] !== $b['health_score']) {
            return $b['health_score'] <=> $a['health_score']; // healthier first
        }
        if ($a['priority'] !== $b['priority']) {
            return $b['priority'] <=> $a['priority']; // higher priority first
        }

        // Lower latency first; an untried transport (null) sorts after a known-fast one.
        $la = $a['latency_ema_ms'] ?? PHP_INT_MAX;
        $lb = $b['latency_ema_ms'] ?? PHP_INT_MAX;

        return $la <=> $lb;
    }

    /** Best-effort transport label for a bare legacy URL (back-compat rung only). */
    private function inferTransport(string $url): string
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if ($host !== '' && str_ends_with($host, '.onion')) {
            return 'onion';
        }
        // Yggdrasil lives in 200::/7 — IPv6 whose first hextet is 0x0200..0x03ff.
        if (str_contains($host, ':')) {
            $first = hexdec((string) (explode(':', $host)[0] ?: '0'));
            if ($first >= 0x0200 && $first <= 0x03ff) {
                return 'yggdrasil';
            }
        }
        // Tailscale CGNAT range 100.64.0.0/10 (100.64.x .. 100.127.x).
        if (preg_match('/^100\.(\d+)\./', $host, $m) && (int) $m[1] >= 64 && (int) $m[1] <= 127) {
            return 'tailnet';
        }

        return 'https';
    }
}
