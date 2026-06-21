<?php

namespace App\Console\Commands;

use App\Models\FederationPeer;
use App\Services\Federation\PeerService;
use Illuminate\Console\Command;

/**
 * federation:peer:handshake {peer} — initiate the trust handshake with a peer
 * already discovered (resolve by server_id or URL). Mutual TOFU: we present our
 * identity, pin theirs, and both reach trust_established.
 *
 *   php artisan federation:peer:handshake http://host.docker.internal:8080
 *   php artisan federation:peer:handshake <their-server-id>
 */
class FederationPeerHandshakeCommand extends Command
{
    protected $signature = 'federation:peer:handshake {peer : Peer server_id or URL}';

    protected $description = 'Initiate the trust handshake with a discovered federation peer';

    public function handle(PeerService $peers): int
    {
        $needle = (string) $this->argument('peer');

        $peer = FederationPeer::query()
            ->matchingNeedle($needle)
            ->first();

        if ($peer === null) {
            $this->error("No discovered peer matches [{$needle}] — run federation:peer:discover first.");

            return self::FAILURE;
        }

        try {
            $peer = $peers->initiateHandshake($peer);
        } catch (\Throwable $e) {
            $this->error('Handshake failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Trust established.');
        $this->line('  server_id : '.$peer->server_id);
        $this->line('  status    : '.$peer->status);

        return self::SUCCESS;
    }
}
