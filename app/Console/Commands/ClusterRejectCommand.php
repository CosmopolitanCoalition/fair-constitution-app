<?php

namespace App\Console\Commands;

use App\Services\Mirror\MirrorService;
use Illuminate\Console\Command;

/**
 * Phase G (G3) — host operator rejects a pending mirror-adoption request.
 */
class ClusterRejectCommand extends Command
{
    protected $signature = 'cluster:reject {request_id : the pending request id}';

    protected $description = 'Reject a pending mirror-adoption request.';

    public function handle(MirrorService $mirror): int
    {
        $mirror->rejectRequest((string) $this->argument('request_id'));
        $this->info('Rejected.');

        return self::SUCCESS;
    }
}
