<?php

namespace App\Console\Commands;

use App\Services\Autoscale\AutoscaleRunControl;
use Illuminate\Console\Command;

/**
 * CLI twin of the Step-3 lane Kill button (UI/CLI parity, standing rule).
 * Kills one lane by its lease id: terminates its database backend, parks the
 * scope in hand in review, returns any batch remainder to the pile, deletes
 * the lease. The pump's kill sweep does the same for a lease stamped through
 * the web endpoint.
 */
class AutoscaleLaneKillCommand extends Command
{
    protected $signature = 'autoscale:lane-kill {lease : the autoscale_worker_leases.id (full UUID)}';

    protected $description = 'Kill one autoscale lane: park its scope in review, release its batch remainder, drop its lease';

    public function handle(AutoscaleRunControl $control): int
    {
        $result = $control->killLease((string) $this->argument('lease'), 'killed by operator (cli)');
        if ($result === null) {
            $this->error('No such lease.');

            return self::FAILURE;
        }
        $this->info(sprintf(
            'Lane killed: backend terminated=%s, scopes parked=%d, released=%d, headers handed=%d',
            $result['terminated'] ? 'yes' : 'no',
            $result['parked'],
            $result['released'],
            $result['headers_handed'],
        ));

        return self::SUCCESS;
    }
}
