<?php

namespace App\Console\Commands;

use App\Models\FederationPeer;
use App\Services\Federation\ColdSyncService;
use Illuminate\Console\Command;

/**
 * federation:cold-sync {peer} — pull a trusted peer's full public corpus in
 * bounded, resumable, signed pages (Phase G). Resumes an open cursor; safe to
 * re-run. `--pages` bounds this run (0 = until caught up).
 *
 *   php artisan federation:cold-sync http://host.docker.internal:8080
 */
class FederationColdSyncCommand extends Command
{
    protected $signature = 'federation:cold-sync
                            {peer : Peer server_id or URL}
                            {--pages=0 : Max pages this run (0 = until caught up)}';

    protected $description = 'Pull a peer\'s full public corpus in bounded, resumable, signed pages';

    public function handle(ColdSyncService $cold): int
    {
        $needle = (string) $this->argument('peer');

        $peer = FederationPeer::query()
            ->matchingNeedle($needle)
            ->first();

        if ($peer === null || ! $peer->isTrusted()) {
            $this->error("No trusted peer matches [{$needle}] — handshake first.");

            return self::FAILURE;
        }

        $cursor = $cold->pull($peer, (int) $this->option('pages'));

        $this->info('Cold sync '.$cursor->status.'.');
        $this->line('  pages applied  : '.$cursor->pages_applied);
        $this->line('  records applied: '.$cursor->records_applied);
        $this->line('  next from seq  : '.$cursor->next_from_seq);

        if ($cursor->status === 'aborted') {
            $this->error('  aborted: '.$cursor->abort_reason);
        }

        return self::SUCCESS;
    }
}
