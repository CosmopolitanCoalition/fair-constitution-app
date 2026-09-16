<?php

namespace App\Jobs\I18n;

use App\Services\I18n\LanguagePackageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;

/**
 * W-0446 — build a language package (operator ruling 2026-09-15).
 *
 * Runs scripts/i18n/export_master.py --locale <code> --out <run>/export in the
 * container (python3), then zips the chunk files into <run>/<code>-package.zip.
 * For the source locale (en) no script runs: the English catalogues are copied
 * as they stand (LanguagePackageService::stageSourceMaster) and zipped as the
 * English master.
 * The board's card polls the run record and offers the zip once the state is
 * ready. The script NEVER runs inside the web request — only here.
 *
 * THE LANE. Both package jobs ride the long-running supervisor
 * (connection redis-long, queue long-running: no worker timeout, 4 h
 * retry_after), never the 60 s default lane that killed the first Hindi
 * export (2026-09-15). The job's own timeout matches the script budget.
 *
 * The run record is the single source of truth the card reads:
 *   status: exporting -> ready | failed
 */
class ExportLanguagePackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** A long export must not be reaped mid-write; the whole run is one chunk here. */
    private const PROCESS_TIMEOUT_SECONDS = 1800;

    /** The worker's ceiling for this job: the script budget, never the default lane's 60 s. */
    public int $timeout = self::PROCESS_TIMEOUT_SECONDS;

    public const CONNECTION = 'redis-long';
    public const QUEUE = 'long-running';

    public function __construct(public string $run, public string $locale)
    {
        $this->onConnection(self::CONNECTION)->onQueue(self::QUEUE);
    }

    public function handle(LanguagePackageService $packages): void
    {
        $packages->assertExportable($this->locale);

        $packages->writeRun($this->run, [
            'kind' => 'export',
            'locale' => $this->locale,
            'status' => 'exporting',
            'started_at' => now()->toIso8601String(),
        ]);

        try {
            if ($packages->isSourceLocale($this->locale)) {
                $packages->stageSourceMaster($this->run);
            } else {
                $cmd = $packages->exportCommand($this->locale, $this->run);
                $packages->ensureDir($packages->exportDir($this->run));

                $process = new Process($cmd, base_path());
                $process->setTimeout(self::PROCESS_TIMEOUT_SECONDS);
                $process->run();

                if (! $process->isSuccessful()) {
                    $packages->writeRun($this->run, [
                        'status' => 'failed',
                        'error' => trim($process->getErrorOutput() ?: $process->getOutput()),
                        'finished_at' => now()->toIso8601String(),
                    ]);

                    return;
                }
            }

            $zipPath = $packages->packageZipPath($this->run, $this->locale);
            $fileCount = $packages->zipDir(
                $packages->exportDir($this->run) . '/' . $this->locale,
                $zipPath,
            );

            $packages->writeRun($this->run, [
                'status' => 'ready',
                'files' => $fileCount,
                'zip' => basename($zipPath),
                'finished_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            $packages->writeRun($this->run, [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now()->toIso8601String(),
            ]);

            throw $e;
        }
    }

    /**
     * The worker killed or crashed this job (a queue timeout, a lost worker,
     * an uncaught error). Without this hook the run record keeps its in-flight
     * status forever and the card shows a job that no longer exists
     * (the Hindi export of 2026-09-15, killed at the 60 s default-queue
     * timeout). Laravel calls failed() for every terminal failure.
     */
    public function failed(?\Throwable $e = null): void
    {
        try {
            app(LanguagePackageService::class)->writeRun($this->run, [
                'status' => 'failed',
                'error' => $e?->getMessage() ?? 'job failed',
                'finished_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable) {
            // The record itself is unreachable; the failed_jobs row still holds the cause.
        }
    }
}
