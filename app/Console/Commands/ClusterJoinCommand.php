<?php

namespace App\Console\Commands;

use App\Models\ClusterMembership;
use App\Services\Mirror\MirrorService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase G (G2) — join a cluster as a read-only mirror: adopt against a host with a
 * join key, then backfill the host's public corpus in bounded signed pages.
 */
class ClusterJoinCommand extends Command
{
    protected $signature = 'cluster:join
        {host_url : the host base URL, e.g. https://host.example}
        {--key= : the join key plaintext (handle.secret)}';

    protected $description = 'Join a cluster as a read-only mirror (adopt with a join key + backfill).';

    public function handle(MirrorService $mirror): int
    {
        $key = (string) $this->option('key');

        if ($key === '') {
            $this->error('A join key is required: --key=handle.secret');

            return self::FAILURE;
        }

        try {
            $membership = $mirror->joinHost((string) $this->argument('host_url'), $key);
        } catch (Throwable $e) {
            $this->error('Join failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info($membership->state === ClusterMembership::STATE_LIVE
            ? 'Joined — this instance is now a read-only mirror.'
            : 'Adoption accepted; backfill in progress (the CLK-20 heartbeat will finish it).');

        return self::SUCCESS;
    }
}
