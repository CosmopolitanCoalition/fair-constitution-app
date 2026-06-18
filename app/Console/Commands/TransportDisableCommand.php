<?php

namespace App\Console\Commands;

use App\Services\Federation\TransportService;
use Illuminate\Console\Command;

/**
 * transport:disable {transport} — stop advertising + dialing one of THIS node's
 * transports (Phase G, G8/G8b). Reversible: transport:register re-enables it. Used
 * when a channel's daemon is being taken down (e.g. retiring a Tor hidden service).
 */
class TransportDisableCommand extends Command
{
    protected $signature = 'transport:disable {transport : https|tailnet|onion|sneakernet|yggdrasil}';

    protected $description = 'Disable one of this node\'s federation transports';

    public function handle(TransportService $transports): int
    {
        $transport = (string) $this->argument('transport');

        if ($transports->disableSelf($transport)) {
            $this->info("Disabled {$transport}. It is no longer advertised or dialed (re-register to re-enable).");

            return self::SUCCESS;
        }

        $this->warn("No enabled '{$transport}' transport found for this node.");

        return self::SUCCESS;
    }
}
