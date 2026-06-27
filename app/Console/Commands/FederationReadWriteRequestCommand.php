<?php

namespace App\Console\Commands;

use App\Services\Mirror\MirrorService;
use Illuminate\Console\Command;
use Throwable;

/**
 * federation:request-read-write {jurisdiction} — ARCHIVIST side (roles campaign Phase 3). A pinned mirror
 * petitions its host for read-write authority over a jurisdiction subtree (Art. V §7). The host RECORDS the
 * petition; its government decides — granting is the governed dual-passage flip (LocalAutonomyService / the
 * authority flip), NEVER this command. CLI driver for the campaign; the console exposes the same flow under
 * the Archivist role card.
 *
 *   php artisan federation:request-read-write <root-jurisdiction-id> [--note="..."]
 */
class FederationReadWriteRequestCommand extends Command
{
    protected $signature = 'federation:request-read-write
                            {jurisdiction : root jurisdiction UUID of the subtree to petition for}
                            {--note= : an optional message to the host operator}';

    protected $description = 'Petition our host for read-write authority over a jurisdiction subtree (Archivist; Art. V §7)';

    public function handle(MirrorService $mirror): int
    {
        try {
            $result = $mirror->petitionReadWrite(
                (string) $this->argument('jurisdiction'),
                $this->option('note') !== null ? (string) $this->option('note') : null,
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('[PETITIONED] the host recorded the read-write request.');
        $this->line('  request id : '.($result['request_id'] ?? '(none)'));
        $this->line('  state      : '.($result['state'] ?? '(unknown)'));
        $this->comment("Granting is the host government's governed flip — not this command.");

        return self::SUCCESS;
    }
}
