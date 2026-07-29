<?php

namespace App\Console\Commands;

use App\Services\MapDataImportService;
use Illuminate\Console\Command;

/**
 * maps:import — CLI half of the portable-archive import (UI↔CLI parity,
 * ruling 10), and the missing seam the cloud-launch plan named: the only
 * import door was a browser multipart upload, so a planet-scale bundle
 * could not be restored at all.
 *
 * Uses MapDataImportService::importSeedFromFile — the IDENTITY-SAFE path:
 * it never truncates cosmic_addresses onto instance_settings, so this box
 * keeps its own server_id + keypair while adopting the donor's foundation
 * (a file restore yields a SOVEREIGN node; only a peer join makes a
 * mirror). The guard lives in the service, so the CLI and any future UI
 * door share it by construction.
 */
class MapsImportCommand extends Command
{
    protected $signature = 'maps:import
        {file : Path to the .tar.gz produced by maps:export / the export panel}';

    protected $description = 'Import a portable map-data archive identity-safely (this box keeps its own federation identity)';

    public function handle(MapDataImportService $import): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("No archive at {$file}.");

            return self::FAILURE;
        }

        $this->line('Importing (identity-safe — this box keeps its own server_id + keys) …');

        $result = $import->importSeedFromFile($file);

        $this->info('✓ Imported at '.$result['imported_at']);
        foreach ($result['tables_restored'] as $table) {
            $this->line("  restored  {$table}");
        }

        return self::SUCCESS;
    }
}
