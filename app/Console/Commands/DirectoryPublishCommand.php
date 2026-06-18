<?php

namespace App\Console\Commands;

use App\Models\Jurisdiction;
use App\Services\Federation\DirectoryService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\TransportService;
use Illuminate\Console\Command;

/**
 * directory:publish [jurisdiction] — publish THIS node's full endpoint set into the
 * G9 directory as the route to a jurisdiction it serves (Phase G, G8b/G9). With a
 * jurisdiction id, publishes that one; without, publishes for every jurisdiction this
 * server is EXPLICITLY authoritative for (authoritative_server_id = our id) — never
 * the implicit null-authority set, so a single-instance node does not blanket the
 * whole tree. The entry is signed and advisory (a route hint, never authority).
 */
class DirectoryPublishCommand extends Command
{
    protected $signature = 'directory:publish {jurisdiction? : a jurisdiction UUID; omitted = all this server is explicitly authoritative for}';

    protected $description = 'Publish this node\'s transport endpoints into the federation directory';

    public function handle(DirectoryService $directory, TransportService $transports, InstanceIdentityService $identity): int
    {
        $endpoints = $transports->selfEndpoints();

        if ($endpoints === []) {
            $this->warn('No transports registered — run transport:register first.');

            return self::FAILURE;
        }

        $targets = $this->argument('jurisdiction')
            ? [(string) $this->argument('jurisdiction')]
            : Jurisdiction::query()->where('authoritative_server_id', $identity->serverId())->pluck('id')->all();

        if ($targets === []) {
            $this->warn('No jurisdiction is explicitly authoritative to this server — pass a jurisdiction id to publish.');

            return self::FAILURE;
        }

        foreach ($targets as $jurisdictionId) {
            $directory->publish((string) $jurisdictionId, $endpoints);
        }

        $this->info('Published '.count($targets).' directory entr'.(count($targets) === 1 ? 'y' : 'ies')
            .' with '.count($endpoints).' endpoint'.(count($endpoints) === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
