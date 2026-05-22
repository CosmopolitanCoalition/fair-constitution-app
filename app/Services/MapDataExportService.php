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
 *   - jurisdictions             (geometry + parent links)
 *   - worldpop_rasters          (population tiles)
 *   - geoboundary_metadata      (display names, region info)
 *   - constitutional_settings   (per-jurisdiction amendments)
 *   - instance_settings         (operator-recorded acceptance + setup state)
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
     * before children.
     *
     * @var list<string>
     */
    public const TABLES = [
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
     * @param  ?string  $tmpDir       Override storage_path('app/exports')
     * @param  bool     $skipRasters  Drop worldpop_rasters from the dump
     *                                (~7 GB saved, useful for migrating
     *                                 jurisdictions+meta+settings only)
     * @param  ?string  $stagingId    Override the auto-generated staging id
     *                                (used by ExportMapDataJob to align
     *                                 status-file ID with archive filename)
     */
    public function export(?string $tmpDir = null, bool $skipRasters = false, ?string $stagingId = null): string
    {
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
        $this->runPgDump($dumpPath, $tables);

        // ── tar.gz ─────────────────────────────────────────────────────────
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
     * @param  list<string>  $tables  Tables to include (caller filters
     *                                self::TABLES vs RASTER_TABLES based
     *                                on the skip_rasters flag).
     */
    private function runPgDump(string $outPath, array $tables): void
    {
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

        $proc = new Process($argv);
        $proc->setTimeout(0);
        $proc->setEnv(['PGPASSWORD' => $cfg['password']]);
        $proc->mustRun();
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
