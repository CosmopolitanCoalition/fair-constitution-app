<?php

namespace App\Jobs;

use App\Services\MapDataExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase P.9 — async export job.
 *
 * The synchronous `GET /api/export/jurisdictions` route streams the tarball
 * directly to the browser, but at world scale the build can take 20+
 * minutes (worldpop_rasters dominates: ~7 GB to read+compress). Browsers
 * and reverse proxies time out. This job runs the same export() in the
 * background via Horizon and writes status to disk:
 *
 *   storage/app/exports/<id>.status.json   — {status, started_at,
 *                                              completed_at, error,
 *                                              archive_filename, size_bytes,
 *                                              skip_rasters}
 *   storage/app/exports/<id>.tar.gz        — only present when status=done
 *
 * The wizard polls `/api/export/jurisdictions/list`, surfaces a row per
 * status file, and links to `/api/export/jurisdictions/download/<file>`
 * once the archive exists.
 */
class ExportMapDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;   // 2 h ceiling — worldpop_rasters at world scale takes ~20–30 min
    public int $tries   = 1;       // re-running a half-finished pg_dump is wasteful

    public function __construct(
        public readonly string $exportId,
        public readonly bool   $skipRasters = false,
    ) {}

    public function handle(MapDataExportService $svc): void
    {
        $statusPath = $this->statusPathFor($this->exportId);
        $this->writeStatus($statusPath, [
            'export_id'    => $this->exportId,
            'status'       => 'running',
            'skip_rasters' => $this->skipRasters,
            'started_at'   => now()->toIso8601String(),
            'completed_at' => null,
            'error'        => null,
            'archive_filename' => null,
            'size_bytes'   => null,
        ]);

        try {
            // The service appends ".tar.gz" to the staging id, so the
            // resulting filename is `${exportId}.tar.gz`. By passing the
            // exportId as the staging id we get a predictable filename
            // the listing endpoint can match against the status file.
            $archivePath = $svc->export(
                tmpDir: storage_path('app/exports'),
                skipRasters: $this->skipRasters,
                stagingId: $this->exportId,
            );

            $this->writeStatus($statusPath, [
                'export_id'        => $this->exportId,
                'status'           => 'done',
                'skip_rasters'     => $this->skipRasters,
                'started_at'       => $this->readStatus($statusPath)['started_at'] ?? null,
                'completed_at'     => now()->toIso8601String(),
                'error'            => null,
                'archive_filename' => basename($archivePath),
                'size_bytes'       => @filesize($archivePath) ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::error("ExportMapDataJob {$this->exportId} failed: ".$e->getMessage());
            $this->writeStatus($statusPath, [
                'export_id'        => $this->exportId,
                'status'           => 'failed',
                'skip_rasters'     => $this->skipRasters,
                'started_at'       => $this->readStatus($statusPath)['started_at'] ?? null,
                'completed_at'     => now()->toIso8601String(),
                'error'            => $e->getMessage(),
                'archive_filename' => null,
                'size_bytes'       => null,
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        // Defensive — Horizon may surface a non-handled() failure. Mirror
        // the failed-status write so the UI doesn't show a stuck "running".
        $statusPath = $this->statusPathFor($this->exportId);
        $prev       = $this->readStatus($statusPath);
        $this->writeStatus($statusPath, array_merge($prev, [
            'status'       => 'failed',
            'completed_at' => now()->toIso8601String(),
            'error'        => $e->getMessage(),
        ]));
    }

    private function statusPathFor(string $id): string
    {
        $dir = storage_path('app/exports');
        if (! is_dir($dir)) @mkdir($dir, 0775, true);
        return "{$dir}/{$id}.status.json";
    }

    /** @return array<string,mixed> */
    private function readStatus(string $path): array
    {
        if (! is_file($path)) return [];
        $raw = @file_get_contents($path);
        if ($raw === false) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeStatus(string $path, array $payload): void
    {
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT));
        @rename($tmp, $path);
    }
}
