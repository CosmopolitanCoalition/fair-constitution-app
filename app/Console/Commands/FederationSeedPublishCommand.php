<?php

namespace App\Console\Commands;

use App\Services\Federation\GeodataSeedTransportService;
use Illuminate\Console\Command;

/**
 * federation:geodata:seed-publish {version} — DONOR side (roles-campaign Phase 0b).
 *
 * Export the geodata FOUNDATION (cosmic_addresses + jurisdictions + worldpop_rasters +
 * geoboundary_metadata + base constitutional_settings — NEVER instance_settings, NEVER
 * institutional tables) to a tarball and publish a signed manifest for it, so a joining
 * mirror can range-pull the seed before draining the audit corpus. Re-publishing the same
 * version rebuilds the tarball + refreshes the manifest. Rasters are INCLUDED in full (the
 * model replicates the foundation to every node — no skipping).
 *
 *   php artisan federation:geodata:seed-publish v1
 */
class FederationSeedPublishCommand extends Command
{
    protected $signature = 'federation:geodata:seed-publish
                            {version : monotone seed version, e.g. v1 (joiners pin this; bump it when the foundation changes)}
                            {--license= : license string stamped on the manifest}';

    protected $description = 'Build the geodata-foundation seed tarball and publish its signed manifest (donor side)';

    public function handle(GeodataSeedTransportService $seed): int
    {
        $version = (string) $this->argument('version');
        $license = $this->option('license');

        $this->info("Building + publishing geodata seed [{$version}] (rasters included — this can take a while)…");

        $result = $seed->publishSeed($version, $license !== null ? (string) $license : null);

        $this->info('Seed published.');
        $this->line('  dataset    : '.$result['dataset']);
        $this->line('  version    : '.$result['version']);
        $this->line('  sha256     : '.$result['sha256']);
        $this->line('  size_bytes : '.number_format((float) $result['size_bytes']));
        $this->line('  file       : '.$result['path']);

        return self::SUCCESS;
    }
}
