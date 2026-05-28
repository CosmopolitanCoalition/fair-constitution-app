<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Phase P.9 — Export the full map-data state of an instance into a portable
 * tarball that can be restored by `MapDataImportService` on a fresh
 * instance. Skips re-running the ETL.
 *
 * Tables included (the full chain that makes a hierarchy queryable):
 *   - cosmic_addresses          (universe→galaxy→planet hierarchy; FK target
 *                                of instance_settings.cosmic_address_id)
 *   - jurisdictions             (geometry + parent links)
 *   - worldpop_rasters          (population tiles)
 *   - geoboundary_metadata      (display names, region info)
 *   - constitutional_settings   (per-jurisdiction amendments)
 *   - instance_settings         (operator-recorded acceptance + setup state;
 *                                FK → cosmic_addresses, so cosmic_addresses
 *                                must restore first — see TABLES order)
 *
 * pg_dump's `--data-only --table=` flags pull just the rows; schema is
 * applied on the receiving end via `php artisan migrate:fresh` before
 * the import service loads the data.
 *
 * Output: a tar.gz archive with two members:
 *   manifest.json   → { exported_at, schema_version, source_url_hint, table_counts }
 *   data.dump       → pg_dump custom-format (.dump) of the listed tables
 */
class MapDataExportService
{
    /**
     * Tables included in the export. Order matters for restore: parents
     * before children. The import service reverses this list for the
     * truncation pass so children clear before parents.
     *
     * cosmic_addresses comes first because instance_settings has a FK
     * referencing it; without cosmic_addresses present, pg_restore skips
     * every instance_settings row with `errors ignored on restore` and
     * the receiving instance ends up with empty setup state.
     *
     * @var list<string>
     */
    public const TABLES = [
        'cosmic_addresses',
        'instance_settings',
        'constitutional_settings',
        'geoboundary_metadata',
        'jurisdictions',
        'worldpop_rasters',
    ];

    /**
     * Tables that get filtered out when skip_rasters=true. The receiving
     * import service inspects manifest.included_tables and only truncates
     * those — so a `--skip-rasters` snapshot can be restored over a target
     * that already has its own worldpop_rasters loaded without clobbering.
     *
     * @var list<string>
     */
    public const RASTER_TABLES = ['worldpop_rasters'];

    /**
     * @param  ?string    $tmpDir            Override storage_path('app/exports')
     * @param  bool       $skipRasters       Drop worldpop_rasters from the dump
     *                                       (~7 GB saved, useful for migrating
     *                                        jurisdictions+meta+settings only)
     * @param  ?string    $stagingId         Override the auto-generated staging id
     *                                       (used by ExportMapDataJob to align
     *                                        status-file ID with archive filename)
     * @param  ?\Closure  $onProgress        Optional callback fired ~every 2s while
     *                                       pg_dump runs. Receives an associative
     *                                       array: {phase, bytes_written,
     *                                       estimated_total_bytes, throughput_bps,
     *                                       eta_seconds, elapsed_seconds}. The job
     *                                       layer writes these into .status.json so
     *                                       the UI can render a progress bar + ETA.
     * @param  ?\Closure  $haltCheck         Optional closure that returns true when
     *                                       the operator has requested a halt.
     *                                       When it returns true the pg_dump
     *                                       subprocess is terminated and an
     *                                       \App\Exceptions\ExportHaltedException
     *                                       is thrown so the job can record
     *                                       status=halted.
     */
    public function export(
        ?string $tmpDir = null,
        bool $skipRasters = false,
        ?string $stagingId = null,
        ?\Closure $onProgress = null,
        ?\Closure $haltCheck = null,
    ): string {
        $tmpDir ??= storage_path('app/exports');
        if (! is_dir($tmpDir) && ! @mkdir($tmpDir, 0775, true)) {
            throw new \RuntimeException("Could not create export directory: {$tmpDir}");
        }

        $stagingId ??= 'map-data-' . now()->format('Ymd-His');
        $stageDir   = "{$tmpDir}/{$stagingId}";
        if (! @mkdir($stageDir, 0775, true)) {
            throw new \RuntimeException("Could not create staging directory: {$stageDir}");
        }

        $tables = $skipRasters
            ? array_values(array_diff(self::TABLES, self::RASTER_TABLES))
            : self::TABLES;

        // ── Manifest ───────────────────────────────────────────────────────
        $manifest = [
            'exported_at'    => now()->toIso8601String(),
            'schema_version' => $this->currentSchemaVersion(),
            'source_url_hint'=> config('app.url'),
            'skip_rasters'   => $skipRasters,
            'included_tables'=> $tables,
            'table_counts'   => $this->countRows($tables),
        ];
        file_put_contents("{$stageDir}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));

        // ── pg_dump (data-only) ─────────────────────────────────────────────
        $dumpPath = "{$stageDir}/data.dump";
        $this->runPgDump($dumpPath, $tables, $onProgress, $haltCheck);

        // ── tar.gz ─────────────────────────────────────────────────────────
        // Compressing the staging dir into the final archive. tar -c on a
        // dump file + manifest is usually fast (the dump is already
        // compressed inside pg_dump --format=custom), but emit one progress
        // update so the UI doesn't look stalled while tar runs.
        if ($onProgress) {
            $onProgress([
                'phase'                 => 'compressing',
                'bytes_written'         => is_file($dumpPath) ? (int) filesize($dumpPath) : 0,
                'estimated_total_bytes' => null,
                'throughput_bps'        => null,
                'eta_seconds'           => null,
            ]);
        }
        $archivePath = "{$tmpDir}/{$stagingId}.tar.gz";
        $proc = new Process([
            'tar', '-czf', $archivePath,
            '-C', $tmpDir,
            $stagingId,
        ]);
        $proc->setTimeout(0);
        $proc->mustRun();

        // Cleanup the staging dir; we keep only the archive.
        $this->rmTree($stageDir);

        Log::info("MapDataExportService: wrote {$archivePath} (".filesize($archivePath)." bytes)");
        return $archivePath;
    }

    /**
     * @return array<string, int>
     */
    private function countRows(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            try {
                $out[$t] = (int) DB::scalar("SELECT COUNT(*) FROM {$t}");
            } catch (\Throwable $e) {
                $out[$t] = -1;   // table missing on this instance
            }
        }
        return $out;
    }

    private function currentSchemaVersion(): string
    {
        // Use the latest applied migration name as the schema fingerprint.
        $latest = DB::table('migrations')->orderByDesc('id')->value('migration');
        return (string) ($latest ?? 'unknown');
    }

    /**
     * Run pg_dump in the background while:
     *   - polling the output file size every ~2s and firing $onProgress so
     *     the UI can show a live ETA + bytes-written counter
     *   - polling $haltCheck so the operator can cancel a long-running
     *     dump (worldpop_rasters at world scale takes 20-30 minutes)
     *
     * Uses Symfony\Process::start() instead of mustRun() so we can interleave
     * polling with the running subprocess. On any failure (non-zero exit
     * code, halt, or thrown exception), the partial dump file is unlinked.
     *
     * @param  list<string>  $tables    Tables to include (caller filters
     *                                  self::TABLES vs RASTER_TABLES based
     *                                  on the skip_rasters flag).
     * @param  ?\Closure     $onProgress See export() docblock.
     * @param  ?\Closure     $haltCheck  See export() docblock.
     */
    private function runPgDump(
        string $outPath,
        array $tables,
        ?\Closure $onProgress = null,
        ?\Closure $haltCheck = null,
    ): void {
        $cfg = config('database.connections.'.config('database.default'));
        $argv = ['pg_dump',
            '--host=' . $cfg['host'],
            '--port=' . $cfg['port'],
            '--username=' . $cfg['username'],
            '--dbname=' . $cfg['database'],
            '--format=custom',
            '--data-only',
            '--no-owner',
            '--no-privileges',
            '--file=' . $outPath,
        ];
        foreach ($tables as $t) {
            $argv[] = '--table=' . $t;
        }

        // Pre-estimate the compressed-dump size from postgres' raw table
        // sizes. pg_dump --format=custom applies its own gzip-equivalent
        // compression, so the on-disk output is meaningfully smaller than
        // the raw relation size. The 0.42 factor is a rough empirical
        // average for the worktree's data (~7GB raw rasters compress to
        // ~3GB in the dump). The progress UI clamps to [0, 100] so a slight
        // overshoot or undershoot near the end is invisible to the operator.
        $estimatedTotal = $this->estimateDumpSize($tables);

        $proc = new Process($argv);
        $proc->setTimeout(0);
        $proc->setEnv(['PGPASSWORD' => $cfg['password']]);
        $proc->start();

        $startedAt    = microtime(true);
        $lastEmitAt   = 0.0;
        $emitInterval = 2.0;   // seconds — matches the UI's polling cadence

        try {
            while ($proc->isRunning()) {
                // Halt check first — if the operator requested halt, stop
                // pg_dump promptly and surface a structured exception so
                // the calling job can record status=halted (vs failed).
                if ($haltCheck && $haltCheck()) {
                    $proc->stop(5, SIGTERM);  // 5s grace, then SIGKILL
                    @unlink($outPath);
                    throw new \App\Exceptions\ExportHaltedException(
                        'Export halted by operator request.'
                    );
                }

                $now = microtime(true);
                if ($onProgress && ($now - $lastEmitAt) >= $emitInterval) {
                    $lastEmitAt    = $now;
                    $bytesWritten  = is_file($outPath) ? (int) filesize($outPath) : 0;
                    $elapsed       = $now - $startedAt;
                    $throughput    = $elapsed > 0 ? $bytesWritten / $elapsed : 0.0;
                    // Dynamic recalibration: if the initial estimate undershoots
                    // (pg_table_size × compression factor was too optimistic),
                    // bump it so the progress bar doesn't stick at 100% while
                    // bytes keep rolling in. Assume there's still ~20% to go
                    // when we've already crossed the original estimate.
                    if ($estimatedTotal !== null && $bytesWritten > $estimatedTotal) {
                        $estimatedTotal = (int) round($bytesWritten * 1.20);
                    }
                    $eta = ($estimatedTotal !== null && $throughput > 0)
                        ? max(0, (int) round(($estimatedTotal - $bytesWritten) / max($throughput, 1)))
                        : null;
                    $onProgress([
                        'phase'                 => 'dumping',
                        'bytes_written'         => $bytesWritten,
                        'estimated_total_bytes' => $estimatedTotal,
                        'throughput_bps'        => (int) round($throughput),
                        'eta_seconds'           => $eta,
                        'elapsed_seconds'       => (int) round($elapsed),
                    ]);
                }

                usleep(500_000);  // 0.5s — keeps the halt check responsive
            }

            // Process completed — surface any error from pg_dump
            if ($proc->getExitCode() !== 0) {
                @unlink($outPath);
                throw new \RuntimeException(
                    "pg_dump failed (exit {$proc->getExitCode()}): " . $proc->getErrorOutput()
                );
            }

            // Final progress emit so the UI bar hits 100% before tar.gz step
            if ($onProgress) {
                $bytesWritten = is_file($outPath) ? (int) filesize($outPath) : 0;
                $elapsed      = microtime(true) - $startedAt;
                $onProgress([
                    'phase'                 => 'dumping',
                    'bytes_written'         => $bytesWritten,
                    'estimated_total_bytes' => $bytesWritten,   // collapse to actual now
                    'throughput_bps'        => $elapsed > 0 ? (int) round($bytesWritten / $elapsed) : 0,
                    'eta_seconds'           => 0,
                    'elapsed_seconds'       => (int) round($elapsed),
                ]);
            }
        } catch (\App\Exceptions\ExportHaltedException $e) {
            // Re-throw for the calling layer to handle without converting
            // to a generic RuntimeException.
            throw $e;
        } catch (\Throwable $e) {
            // Any other failure: ensure pg_dump is dead, then bubble up.
            if ($proc->isRunning()) $proc->stop(2, SIGKILL);
            @unlink($outPath);
            throw $e;
        }
    }

    /**
     * Estimate the on-disk size of a pg_dump --format=custom file for the
     * given tables. Used to drive the progress bar's percentage + ETA.
     *
     * Uses `pg_table_size` (main fork + TOAST + FSM/VM) rather than
     * `pg_relation_size` (main only). The worldpop_rasters table stores
     * its raster pixel data as bytea, which postgres puts in the TOAST
     * relation; without TOAST in the estimate, the bar underestimates by
     * ~50% on raster-heavy dumps. pg_table_size excludes indexes, which
     * matches pg_dump --data-only's behavior.
     *
     * Compression factor 0.85: pg_dump custom-format applies gzip-level
     * compression, but already-compressed bytea + integer-heavy raster
     * data don't shrink much. Empirically a full worldpop_rasters dump
     * lands around 70-90% of the on-disk table size. The dynamic
     * recalibration in runPgDump() corrects any remaining drift by
     * bumping the estimate if bytes_written exceeds it.
     *
     * @param  list<string>  $tables
     */
    private function estimateDumpSize(array $tables): ?int
    {
        try {
            $totalRaw = 0;
            foreach ($tables as $t) {
                $bytes = (int) DB::scalar("SELECT pg_table_size(?)", [$t]);
                $totalRaw += $bytes;
            }
            return $totalRaw > 0 ? (int) round($totalRaw * 0.85) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function rmTree(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $this->rmTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }
}
