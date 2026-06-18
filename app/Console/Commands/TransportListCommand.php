<?php

namespace App\Console\Commands;

use App\Models\FederationTransport;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Console\Command;

/**
 * transport:list — show THIS node's registered transports, best-first (Phase G,
 * G8/G8b). The read companion to transport:register / transport:disable.
 */
class TransportListCommand extends Command
{
    protected $signature = 'transport:list';

    protected $description = 'List this node\'s registered federation transports';

    public function handle(InstanceIdentityService $identity): int
    {
        $rows = FederationTransport::query()
            ->where('server_id', $identity->serverId())
            ->orderByDesc('priority')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('No transports registered. Use transport:register <transport> <address>.');

            return self::SUCCESS;
        }

        $this->table(
            ['transport', 'address', 'priority', 'enabled'],
            $rows->map(fn (FederationTransport $t) => [
                $t->transport, $t->address, $t->priority, $t->enabled ? 'yes' : 'no',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
