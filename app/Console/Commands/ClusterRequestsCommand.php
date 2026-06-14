<?php

namespace App\Console\Commands;

use App\Services\Mirror\MirrorService;
use Illuminate\Console\Command;

/**
 * Phase G (G3) — the host operator's pending mirror-adoption review queue.
 */
class ClusterRequestsCommand extends Command
{
    protected $signature = 'cluster:requests';

    protected $description = 'List pending keyless mirror-adoption requests awaiting approval.';

    public function handle(MirrorService $mirror): int
    {
        $rows = $mirror->pendingRequests()->map(fn ($r) => [
            $r->id,
            $r->applicant_server_id,
            $r->created_at?->toIso8601String() ?? '—',
        ])->all();

        if ($rows === []) {
            $this->info('No pending adoption requests.');

            return self::SUCCESS;
        }

        $this->table(['request_id', 'applicant_server_id', 'requested_at'], $rows);
        $this->line('Approve with: cluster:approve {request_id}   ·   reject with: cluster:reject {request_id}');

        return self::SUCCESS;
    }
}
