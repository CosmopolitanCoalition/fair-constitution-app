<?php

namespace App\Console\Commands;

use App\Models\FederationPeer;
use App\Services\Federation\FederationSyncService;
use Illuminate\Console\Command;

/**
 * federation:sync:push {peer?} — build our signed audit tail + public records
 * and push it to a trusted peer's /sync (Full Faith & Credit). With no argument,
 * pushes to every trust_established peer (the CLK-20 cadence, run by hand).
 *
 *   php artisan federation:sync:push http://host.docker.internal:8080
 *   php artisan federation:sync:push        # all trusted peers
 */
class FederationSyncPushCommand extends Command
{
    protected $signature = 'federation:sync:push {peer? : Peer server_id or URL (default: all trusted peers)}';

    protected $description = 'Push our Full-Faith-&-Credit tail to trusted federation peers';

    public function handle(FederationSyncService $sync): int
    {
        $needle = $this->argument('peer');

        // FF&C only ever flows to a TRUSTED peer — require trust_established in BOTH modes.
        // A bare server_id/URL arg used to skip this filter (only the no-arg path applied it),
        // so naming a merely-discovered or untrusted peer targeted it anyway and failed deep in
        // pushTo with a confusing error instead of a clear "no trusted peer" message. Step 9 of
        // FRESH-NODE-START.md runs AFTER the handshake, so the named peer is trusted by then.
        $peers = FederationPeer::query()
            ->whereNull('deleted_at')
            ->where('status', FederationPeer::STATUS_TRUST_ESTABLISHED)
            ->when($needle !== null, fn ($q) => $q->matchingNeedle((string) $needle))
            ->get();

        if ($peers->isEmpty()) {
            $this->warn('No matching trusted peer to push to.');

            return self::SUCCESS;
        }

        foreach ($peers as $peer) {
            try {
                $log = $sync->pushTo($peer);
                $this->line(sprintf('  → %s : %s (%s)', $peer->name ?? $peer->server_id, $log->result, $peer->url));
            } catch (\Throwable $e) {
                $this->error(sprintf('  → %s : FAILED — %s', $peer->server_id, $e->getMessage()));
            }
        }

        return self::SUCCESS;
    }
}
