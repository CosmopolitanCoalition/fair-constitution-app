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
 * The board's card polls the run record and offers the zip once the state is
 * ready. The script NEVER runs inside the web request — only here.
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

    public function __construct(public string $run, public string $locale)
    {
    }

    public function handle(LanguagePackageService $packages): void
    {
        $packages->assertTargetLocale($this->locale);

        $packages->writeRun($this->run, [
            'kind' => 'export',
            'locale' => $this->locale,
            'status' => 'exporting',
            'started_at' => now()->toIso8601String(),
        ]);

        try {
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
}
