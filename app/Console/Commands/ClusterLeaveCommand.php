<?php

namespace App\Console\Commands;

use App\Services\Mirror\MirrorService;
use Illuminate\Console\Command;

/**
 * Phase G (G2) — leave the cluster: stop being a read-only mirror (the engine
 * write-guard switches off, the active mirror membership departs).
 */
class ClusterLeaveCommand extends Command
{
    protected $signature = 'cluster:leave';

    protected $description = 'Leave the cluster: stop being a read-only mirror.';

    public function handle(MirrorService $mirror): int
    {
        if (! $mirror->isMirror()) {
            $this->info('This instance is not a mirror — nothing to leave.');

            return self::SUCCESS;
        }

        $mirror->leave();
        $this->info('Left the cluster — this instance is no longer a mirror.');

        return self::SUCCESS;
    }
}
