<?php

namespace App\Services\Federation;

use App\Models\FederationTransportHealth;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * The multiplex survival-mesh dialer (Phase G, G8b) — "if one survives, all survive."
 *
 * It does NOT replace FederationClient (the PROTECTED signing seam). It sits above
 * it: it asks TransportEndpoints for a peer's failover ladder and hands Federation
 * the SAME signed bytes over each base URL in turn until one delivers. Because the
 * signature covers METHOD\nTARGET\nTIMESTAMP\nsha256(body) — never the host — a
 * transport swap can neither alter nor forge a federation message; failover is purely
 * a question of which base URL to retry over.
 *
 * An HTTP RESPONSE of any status (a 2xx, or a 4xx that is an authoritative refusal)
 * means the channel delivered bytes → the transport is healthy and we return the
 * response intact. Only a transport-level failure (connect/timeout) counts against a
 * circuit and triggers failover to the next survivor. A transport whose circuit is
 * OPEN within its cooldown is skipped fast on the first pass and tried only as a last
 * resort, so the common path never pays a timeout on a known-dead channel.
 */
class MultiplexClient
{
    public function __construct(
        private readonly FederationClient $client,
        private readonly TransportEndpoints $endpoints,
    ) {}

    /**
     * Reach a peer over its best surviving transport.
     *
     * @param  array<string,mixed>  $payload  GET query, or POST body
     *
     * @throws NoSurvivingTransport when every dialable transport fails or is undialable
     */
    public function reach(string $serverId, string $method, string $path, array $payload = []): Response
    {
        $ladder = $this->endpoints->forPeer($serverId);

        // Only channels we can actually dial from here (e.g. onion needs a local Tor
        // SOCKS proxy; sneakernet is an offline bundle, not a synchronous request).
        $dialable = array_values(array_filter(
            $ladder,
            fn (array $c) => $this->transportLocallyAvailable($c['transport']),
        ));

        // Pass 1 — healthy/cooled rungs, best-first (fail fast past open circuits).
        foreach ($dialable as $cand) {
            if (! $cand['attemptable']) {
                continue;
            }
            if (($resp = $this->dial($serverId, $cand, $method, $path, $payload)) !== null) {
                return $resp;
            }
        }

        // Pass 2 — every healthy rung failed (or there were none): a tripped circuit
        // is better than no reach at all, so try the open ones we skipped. This keeps
        // a recovered-but-still-open transport from bricking a peer forever.
        foreach ($dialable as $cand) {
            if ($cand['attemptable']) {
                continue;
            }
            if (($resp = $this->dial($serverId, $cand, $method, $path, $payload)) !== null) {
                return $resp;
            }
        }

        throw new NoSurvivingTransport($serverId);
    }

    /**
     * CLK-20 maintenance probe — re-learn a peer's NOT-healthy transports so a degraded
     * channel is rediscovered even when a healthy sibling is carrying all the traffic.
     * Without this the multiplex would MASK a real outage: the preferred channel could
     * stay down indefinitely because reach() keeps succeeding over a fallback and never
     * retries the dead one. Dials a cheap GET /identity over each open/half-open rung,
     * recording the outcome; healthy rungs are left alone. Returns the number probed.
     */
    public function probeUnhealthy(string $serverId): int
    {
        $probed = 0;

        foreach ($this->endpoints->forPeer($serverId) as $cand) {
            if ($cand['circuit'] === FederationTransportHealth::CIRCUIT_CLOSED) {
                continue; // healthy — nothing to re-learn
            }
            if (! $this->transportLocallyAvailable($cand['transport'])) {
                continue;
            }

            $this->dial($serverId, $cand, 'GET', '/api/federation/identity', []);
            $probed++;
        }

        return $probed;
    }

    /**
     * Dial ONE candidate. Returns the Response on delivery (any HTTP status), or null
     * on a transport-level failure (already recorded as a circuit failure).
     */
    private function dial(string $serverId, array $cand, string $method, string $path, array $payload): ?Response
    {
        $startedNs = hrtime(true);

        try {
            $resp = strtoupper($method) === 'GET'
                ? $this->client->get($cand['url'], $path, $payload)
                : $this->client->post($cand['url'], $path, $payload);

            $this->markHealthy($serverId, $cand['transport'], $cand['url'], (int) round((hrtime(true) - $startedNs) / 1e6));

            return $resp;
        } catch (ConnectionException) {
            $this->markDown($serverId, $cand['transport'], $cand['url']);

            return null;
        }
    }

    /** Whether this node can even attempt a given transport right now. */
    private function transportLocallyAvailable(string $transport): bool
    {
        return match ($transport) {
            // .onion is only reachable through a configured local Tor SOCKS proxy.
            'onion' => filled(config('cga.federation_socks_proxy')),
            // sneakernet is an offline export/import bundle, never a live request.
            'sneakernet' => false,
            default => true,
        };
    }

    /** A delivery: reset failures, refresh latency EMA, close the circuit. */
    private function markHealthy(string $serverId, string $transport, string $url, int $latencyMs): void
    {
        $this->record($serverId, $transport, $url, function (FederationTransportHealth $h) use ($latencyMs) {
            $prev = $h->latency_ema_ms;
            $h->latency_ema_ms = $prev === null ? $latencyMs : (int) round(0.3 * $latencyMs + 0.7 * $prev);
            $h->consecutive_failures = 0;
            $h->last_ok_at = now();
            $h->circuit_state = FederationTransportHealth::CIRCUIT_CLOSED;
        });
    }

    /** A transport failure: count it, and trip the circuit OPEN at the threshold. */
    private function markDown(string $serverId, string $transport, string $url): void
    {
        $threshold = max(1, (int) config('cga.federation_transport_failure_threshold', 3));

        $this->record($serverId, $transport, $url, function (FederationTransportHealth $h) use ($threshold) {
            $h->consecutive_failures = (int) $h->consecutive_failures + 1;
            $h->last_fail_at = now();
            if ($h->consecutive_failures >= $threshold) {
                $h->circuit_state = FederationTransportHealth::CIRCUIT_OPEN;
            }
        });
    }

    /**
     * Read-modify-write the health row for ONE endpoint (server, transport, url). The
     * url is part of the key so a dead address never shadows a healthy sibling on the
     * same transport. Health is advisory operational state, so a write race (the
     * partial unique index throwing on a concurrent first insert) is swallowed —
     * bookkeeping must never break the federation call it observes.
     */
    private function record(string $serverId, string $transport, string $url, callable $mutate): void
    {
        try {
            $row = FederationTransportHealth::query()->firstOrNew([
                'server_id' => $serverId,
                'transport' => $transport,
                'url' => $url,
            ]);
            $mutate($row);
            $row->save();
        } catch (QueryException) {
            // ignore — health is best-effort, the dial outcome already stands
        }
    }
}
