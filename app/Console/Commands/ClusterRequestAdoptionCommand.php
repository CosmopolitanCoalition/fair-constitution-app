<?php

namespace App\Console\Commands;

use App\Services\Mirror\MirrorService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase G (G3) — request keyless adoption from a host (mirror side). The host
 * operator must approve before the mirror is admitted; with --wait, poll until
 * approved, then backfill and become a read-only mirror.
 */
class ClusterRequestAdoptionCommand extends Command
{
    protected $signature = 'cluster:request-adoption
        {host_url : the host base URL, e.g. https://host.example}
        {--wait : poll until the host operator approves}';

    protected $description = 'Request keyless adoption from a host (the operator vouches you in).';

    public function handle(MirrorService $mirror): int
    {
        $hostUrl = (string) $this->argument('host_url');
        $wait = (bool) $this->option('wait');
        $tries = $wait ? 120 : 1;

        for ($i = 0; $i < $tries; $i++) {
            try {
                $membership = $mirror->requestJoin($hostUrl);
            } catch (Throwable $e) {
                $this->error('Request failed: '.$e->getMessage());

                return self::FAILURE;
            }

            if ($membership !== null) {
                $this->info('Approved — this instance is now a read-only mirror.');

                return self::SUCCESS;
            }

            if ($i === 0) {
                $this->info("Adoption requested — awaiting the host operator's approval.");
            }
            if ($wait && $i < $tries - 1) {
                sleep(5);
            }
        }

        $this->line($wait
            ? 'Still pending after waiting — re-run once the host approves.'
            : 'Re-run (or pass --wait) once approved on the host.');

        return self::SUCCESS;
    }
}
