<?php

namespace App\Console\Commands;

use App\Domain\Engine\ConstitutionalViolation;
use App\Services\Federation\TransportService;
use Illuminate\Console\Command;

/**
 * transport:register {transport} {address} [--priority=] — record one of THIS node's
 * reachable channels into the transport registry (Phase G, G8/G8b). The bootstrap
 * layer (C7) calls this once per chosen mesh after the daemon is up; previously the
 * registry was only reachable from tinker. Our enabled rows are what /identity
 * advertises and directory:publish publishes.
 *
 *   php artisan transport:register https https://node.example:8081 --priority=200
 *   php artisan transport:register yggdrasil 'http://[200:abcd::1]:8081' --priority=150
 *   php artisan transport:register onion http://abc123.onion --priority=100
 */
class TransportRegisterCommand extends Command
{
    protected $signature = 'transport:register {transport : https|tailnet|onion|sneakernet|yggdrasil} {address : reachable URL/address for this channel} {--priority=100 : higher = preferred}';

    protected $description = 'Register one of this node\'s reachable federation transports';

    public function handle(TransportService $transports): int
    {
        try {
            $row = $transports->registerSelf(
                (string) $this->argument('transport'),
                (string) $this->argument('address'),
                (int) $this->option('priority'),
            );
        } catch (ConstitutionalViolation $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Registered {$row->transport} → {$row->address} (priority {$row->priority}).");

        return self::SUCCESS;
    }
}
