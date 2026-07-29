<?php

namespace App\Console\Commands;

use App\Jobs\ExportMapDataJob;
use Illuminate\Console\Command;

/**
 * maps:export — CLI half of the portable-archive export (UI↔CLI parity,
 * ruling 10). Wraps the SAME ExportMapDataJob the wizard's export panel
 * dispatches: same id convention, same status files under
 * storage/app/exports/, same long-running queue, same halt flag. The
 * export-bundle-equals-seed paradigm: what this produces is what
 * maps:import (and a joining peer) consumes.
 */
class MapsExportCommand extends Command
{
    protected $signature = 'maps:export
        {--skip-rasters : Drop worldpop_rasters from the dump (~7 GB saved)}
        {--tables= : Comma-separated explicit table subset (wins over --skip-rasters)}
        {--sync : Run in-process instead of dispatching to Horizon (small worlds only)}
        {--wait : Dispatch, then poll the status file until done/failed}';

    protected $description = 'Export the portable map-data archive (same job as the wizard\'s export panel)';

    public function handle(): int
    {
        $skipRasters = (bool) $this->option('skip-rasters');
        $tablesOpt = $this->option('tables');
        $tables = is_string($tablesOpt) && trim($tablesOpt) !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $tablesOpt))))
            : null;

        if ($this->option('sync')) {
            $path = app(\App\Services\MapDataExportService::class)
                ->export(skipRasters: $skipRasters, tables: $tables);
            $this->info("✓ Export written: {$path}");

            return self::SUCCESS;
        }

        // The wizard's exact id convention, so the export panel lists this run.
        $exportId = 'map-data-'.now()->format('Ymd-His').'-'.substr(bin2hex(random_bytes(4)), 0, 8);
        ExportMapDataJob::dispatch($exportId, $skipRasters, $tables);

        $statusFile = storage_path("app/exports/{$exportId}.status.json");
        $this->info("✓ Export dispatched: {$exportId}");
        $this->line("  status  {$statusFile}");
        $this->line("  archive storage/app/exports/{$exportId}.tar.gz (when done)");
        $this->line('  halt    the wizard export panel, or DELETE the status row via the same API');

        if (! $this->option('wait')) {
            return self::SUCCESS;
        }

        // Poll the SAME status file the wizard's list endpoint reads.
        $this->line('  waiting …');
        do {
            sleep(5);
            $status = is_file($statusFile)
                ? (json_decode((string) file_get_contents($statusFile), true) ?: [])
                : [];
            $state = (string) ($status['status'] ?? 'queued');
        } while (! in_array($state, ['done', 'failed', 'halted'], true));

        if ($state !== 'done') {
            $this->error("Export {$state}".(isset($status['error']) ? ': '.$status['error'] : '.'));

            return self::FAILURE;
        }

        $this->info('✓ Export done: storage/app/exports/'.((string) ($status['archive_filename'] ?? "{$exportId}.tar.gz")));

        return self::SUCCESS;
    }
}
