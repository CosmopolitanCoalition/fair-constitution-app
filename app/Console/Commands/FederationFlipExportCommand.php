<?php

namespace App\Console\Commands;

use App\Models\FederationPeer;
use App\Models\PartitionExport;
use App\Services\Federation\AuthorityFlipService;
use App\Services\Federation\FederationClient;
use Illuminate\Console\Command;

/**
 * federation:flip:export {jurisdiction} {peer} — export a jurisdiction subtree
 * and flip its authority to a trusted peer (WF-JUR-08). Signs the manifest, sets
 * the subtree's authoritative_server_id → the peer locally, then transmits the
 * bundle to the peer's /flip so it can assume authority.
 *
 *   php artisan federation:flip:export <jurisdiction-uuid> http://host.docker.internal:8080
 */
class FederationFlipExportCommand extends Command
{
    protected $signature = 'federation:flip:export {jurisdiction : Root jurisdiction UUID} {peer : Peer server_id or URL}';

    protected $description = 'Export a jurisdiction partition and flip its authority to a trusted peer';

    public function handle(AuthorityFlipService $flips, FederationClient $client): int
    {
        $needle = (string) $this->argument('peer');

        $peer = FederationPeer::query()
            ->where('server_id', $needle)->orWhere('url', rtrim($needle, '/'))
            ->first();

        if ($peer === null || ! $peer->isTrusted()) {
            $this->error("No trusted peer matches [{$needle}].");

            return self::FAILURE;
        }

        try {
            $export = $flips->exportFlip((string) $this->argument('jurisdiction'), $peer);
        } catch (\Throwable $e) {
            $this->error('Flip export failed: '.$e->getMessage());

            return self::FAILURE;
        }

        // Transmit the signed manifest so the peer assumes authority.
        $response = $client->post($peer->url, '/api/federation/flip', [
            'manifest' => $export->manifest,
            'signature' => $export->signature,
        ]);

        if ($response->successful()) {
            $export->status = PartitionExport::STATUS_FLIP_COMMITTED;
            $export->save();
            $this->info('Authority flipped — the peer assumed the partition.');
        } else {
            $this->warn('Local flip recorded, but the peer did not ACK (HTTP '.$response->status().'). Use revert if needed.');
        }

        $this->line('  jurisdiction : '.$export->jurisdiction_id);
        $this->line('  peer         : '.$peer->server_id);
        $this->line('  descendants  : '.($export->manifest['descendant_count'] ?? '—'));
        $this->line('  status       : '.$export->status);

        return self::SUCCESS;
    }
}
