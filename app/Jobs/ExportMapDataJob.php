<?php

namespace App\Jobs;

use App\Exceptions\ExportHaltedException;
use App\Services\MapDataExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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

    /**
     * @param  ?list<string>  $tables  Optional explicit selection (subset of
     *                                 MapDataExportService::TABLES). When null
     *                                 the legacy $skipRasters bool drives the
     *                                 selection; when non-null it wins.
     */
    public function __construct(
        public readonly string  $exportId,
        public readonly bool    $skipRasters = false,
        public readonly ?array  $tables      = null,
    ) {
        // Route to the long-running Horizon supervisor (timeout=0, memory=512).
        // The default supervisor-1 has timeout=60s which SIGKILLs the worker
        // mid-pg_dump on anything but a tiny dataset — worldpop_rasters at
        // world scale takes 20-30 minutes alone. Without this, the job dies
        // after 60s and Laravel marks it MaxAttemptsExceeded (since tries=1).
        // Mirrors the routing in MassReseedJob.
        $this->onQueue('long-running');
    }

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
            'progress'     => null,   // populated below from $onProgress
        ]);

        // Clear any stale halt flag from a previous attempt before starting.
        $haltKey = "export.{$this->exportId}.halt";
        Cache::forget($haltKey);

        // Progress callback: writes the latest snapshot (phase, bytes_written,
        // estimated total, throughput, ETA) into the status.json `progress`
        // field. The UI polls /api/export/jurisdictions/list at the same
        // ~2s cadence the service emits at, so the bar moves smoothly.
        $onProgress = function (array $p) use ($statusPath) {
            $prev = $this->readStatus($statusPath);
            $this->writeStatus($statusPath, array_merge($prev, [
                'progress'   => $p,
                // Bump completed_at as a heartbeat — a stuck job can be
                // distinguished from an in-progress one by comparing
                // completed_at against wall-clock.
                'updated_at' => now()->toIso8601String(),
            ]));
        };

        // Halt check: poll the cache flag every ~0.5s inside the service's
        // wait loop. /api/export/jurisdictions/{id}/halt sets this flag.
        $haltCheck = fn () => (bool) Cache::get($haltKey, false);

        try {
            $archivePath = $svc->export(
                tmpDir: storage_path('app/exports'),
                skipRasters: $this->skipRasters,
                stagingId: $this->exportId,
                onProgress: $onProgress,
                haltCheck:  $haltCheck,
                tables:     $this->tables,
            );

            $this->writeStatus($statusPath, array_merge(
                $this->readStatus($statusPath),
                [
                    'export_id'        => $this->exportId,
                    'status'           => 'done',
                    'skip_rasters'     => $this->skipRasters,
                    'completed_at'     => now()->toIso8601String(),
                    'error'            => null,
                    'archive_filename' => basename($archivePath),
                    'size_bytes'       => @filesize($archivePath) ?: null,
                ],
            ));
        } catch (ExportHaltedException $e) {
            // Operator-requested halt — record as a distinct state, not a
            // failure. The UI shows halted entries in amber with a delete
            // button (no retry — halts are intentional).
            Log::info("ExportMapDataJob {$this->exportId} halted by operator");
            $this->writeStatus($statusPath, array_merge(
                $this->readStatus($statusPath),
                [
                    'status'       => 'halted',
                    'completed_at' => now()->toIso8601String(),
                    'error'        => null,
                    'archive_filename' => null,
                    'size_bytes'   => null,
                ],
            ));
            // Don't re-throw — we want the job to register as completed
            // (not failed) from Horizon's perspective.
        } catch (\Throwable $e) {
            Log::error("ExportMapDataJob {$this->exportId} failed: ".$e->getMessage());
            $this->writeStatus($statusPath, array_merge(
                $this->readStatus($statusPath),
                [
                    'status'       => 'failed',
                    'completed_at' => now()->toIso8601String(),
                    'error'        => $e->getMessage(),
                    'archive_filename' => null,
                    'size_bytes'   => null,
                ],
            ));
            throw $e;
        } finally {
            // Always clear the halt flag so a re-dispatched job with the same
            // id (unlikely but possible) starts clean.
            Cache::forget($haltKey);
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
        // Make world-writable so PHP-FPM (which runs as www-data) can unlink
        // it from the DELETE endpoint. Horizon writes these files as root,
        // and a default-umask 0644 file isn't deletable by www-data even
        // though the parent dir grants traverse access. 0666 keeps the file
        // readable by everyone and lets either user clean it up.
        @chmod($path, 0666);
    }

    private function statusPathFor(string $id): string
    {
        $dir = storage_path('app/exports');
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        // chmod even if it already existed — the dir may have been created
        // earlier with a tighter mode by a different process / image. Without
        // 0777 the www-data FPM worker can't unlink files inside it.
        @chmod($dir, 0777);
        return "{$dir}/{$id}.status.json";
    }
}
