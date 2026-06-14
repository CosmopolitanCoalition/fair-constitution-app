<?php

namespace App\Console\Commands;

use App\Services\Mirror\AdoptionRejected;
use App\Services\Mirror\MirrorService;
use Illuminate\Console\Command;

/**
 * Phase G (G3) — host operator approves a pending mirror-adoption request. The
 * applicant is pinned as our mirror; it polls /adopt again to finalize + backfill.
 */
class ClusterApproveCommand extends Command
{
    protected $signature = 'cluster:approve {request_id : the pending request id}';

    protected $description = 'Approve a pending mirror-adoption request (vouch the applicant in).';

    public function handle(MirrorService $mirror): int
    {
        try {
            $membership = $mirror->approveRequest((string) $this->argument('request_id'));
        } catch (AdoptionRejected $e) {
            $this->error('Cannot approve: '.$e->reason);

            return self::FAILURE;
        }

        $this->info("Approved — the applicant is now our mirror (membership {$membership->id}).");

        return self::SUCCESS;
    }
}
