<?php

namespace App\Console\Commands;

use App\Services\Federation\PeerService;
use Illuminate\Console\Command;

/**
 * federation:peer:discover {url} — learn a peer's identity and record it
 * (status=discovered). Follow with federation:peer:handshake to establish trust.
 *
 *   php artisan federation:peer:discover http://host.docker.internal:8080
 */
class FederationPeerDiscoverCommand extends Command
{
    protected $signature = 'federation:peer:discover {url : Base URL of the peer instance}';

    protected $description = 'Discover a federation peer by URL and record its public identity';

    public function handle(PeerService $peers): int
    {
        try {
            $peer = $peers->discover((string) $this->argument('url'));
        } catch (\Throwable $e) {
            $this->error('Discovery failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Peer discovered.');
        $this->line('  server_id : '.$peer->server_id);
        $this->line('  name      : '.($peer->name ?? '—'));
        $this->line('  url       : '.$peer->url);
        $this->line('  status    : '.$peer->status);

        return self::SUCCESS;
    }
}
