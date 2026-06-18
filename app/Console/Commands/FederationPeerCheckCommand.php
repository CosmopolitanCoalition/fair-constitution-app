<?php

namespace App\Console\Commands;

use App\Services\Federation\FederationClient;
use Illuminate\Console\Command;

/**
 * federation:peer:check {url} — a WAN reachability + version diagnostic (Phase G,
 * G8b). Dials a peer's PUBLIC /api/federation/identity over the given base URL,
 * reports round-trip latency, and prints the three version axes it advertises
 * (schema / constitutional / app_release). Read-only — it records nothing, pins
 * nothing; it is the "can I even reach this box, and do we count the same way?"
 * triage tool for a cross-NAT link, before discover/handshake.
 *
 *   php artisan federation:peer:check http://[200:abcd::1]:8081
 *   php artisan federation:peer:check http://abc123.onion
 */
class FederationPeerCheckCommand extends Command
{
    protected $signature = 'federation:peer:check {url : Base URL of the peer instance (any transport)}';

    protected $description = 'Probe a peer URL for reachability, latency, and advertised versions (no state change)';

    public function handle(FederationClient $client): int
    {
        $url = rtrim((string) $this->argument('url'), '/');
        $startedMs = (int) (microtime(true) * 1000);

        try {
            $response = $client->get($url, '/api/federation/identity');
        } catch (\Throwable $e) {
            $this->error("Unreachable: {$e->getMessage()}");

            return self::FAILURE;
        }

        $latencyMs = (int) (microtime(true) * 1000) - $startedMs;

        if (! $response->successful()) {
            $this->error("Peer responded HTTP {$response->status()} (latency {$latencyMs}ms) — not a healthy identity endpoint.");

            return self::FAILURE;
        }

        $body = (array) $response->json();

        $this->info("Reachable in {$latencyMs}ms.");
        $this->line('  server_id              : '.($body['server_id'] ?? '—'));
        $this->line('  name                   : '.($body['name'] ?? '—'));
        $this->line('  schema_version         : '.($body['schema_version'] ?? '—'));
        $this->line('  constitutional_version : '.($body['constitutional_version'] ?? '—'));
        $this->line('  app_release            : '.($body['app_release'] ?? '—'));

        return self::SUCCESS;
    }
}
