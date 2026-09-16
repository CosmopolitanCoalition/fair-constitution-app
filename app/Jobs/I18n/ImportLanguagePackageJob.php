<?php

namespace App\Jobs\I18n;

use App\Services\AuditService;
use App\Services\I18n\LanguagePackageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;

/**
 * W-0446 — import a translated package (operator ruling 2026-09-15).
 *
 * Two phases, one job class:
 *   confirm=false  DRY RUN. Runs import_translated.py --dry-run and captures
 *                  accepted / rejected counts into the run record. Writes
 *                  nothing to the catalogs. The card shows the report.
 *   confirm=true   REAL IMPORT. Runs import_translated.py (no --dry-run), then
 *                  refreshes coverage.json with node scripts/i18n/check.mjs
 *                  --warn-only IF node is reachable, else records
 *                  "coverage refresh pending" for the desk. Writes ONE audit
 *                  entry recording locale, counts and the operator.
 *
 * The scripts run here, never in the web request. node lives in the fc_vite
 * container, not fc_app, so the coverage refresh is attempted and, when the
 * binary is absent, is deferred rather than failing the import.
 */
class ImportLanguagePackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    private const PROCESS_TIMEOUT_SECONDS = 1800;

    /** The worker's ceiling for this job: the script budget, never the default lane's 60 s. */
    public int $timeout = self::PROCESS_TIMEOUT_SECONDS;

    public function __construct(
        public string $run,
        public bool $confirm = false,
        public ?string $actorId = null,
        public ?string $locale = null,
    ) {
        // The long lane, the same one the export rides (see ExportLanguagePackageJob).
        $this->onConnection(ExportLanguagePackageJob::CONNECTION)->onQueue(ExportLanguagePackageJob::QUEUE);
    }

    public function handle(LanguagePackageService $packages, AuditService $audit): void
    {
        $target = $packages->importDir($this->run);

        $packages->writeRun($this->run, [
            'kind' => 'import',
            'status' => $this->confirm ? 'importing' : 'dry_running',
            'started_at' => now()->toIso8601String(),
        ]);

        try {
            // A zip upload is unpacked inside the run first; the importer walks the tree.
            $unpacked = $packages->unpackUpload($target);
            if ($unpacked['zips'] > 0) {
                $packages->writeRun($this->run, ['unpacked' => $unpacked]);
            }

            $cmd = $packages->importCommand($target, dryRun: ! $this->confirm, locale: $this->locale);
            $process = new Process($cmd, base_path());
            $process->setTimeout(self::PROCESS_TIMEOUT_SECONDS);
            $process->run();

            $report = $packages->parseDryRunReport($process->getOutput());

            if (! $process->isSuccessful()) {
                $packages->writeRun($this->run, [
                    'status' => 'failed',
                    'report' => $report,
                    'error' => trim($process->getErrorOutput() ?: $process->getOutput()),
                    'finished_at' => now()->toIso8601String(),
                ]);

                return;
            }

            if (! $this->confirm) {
                $packages->writeRun($this->run, [
                    'status' => 'dry_run_ready',
                    'report' => $report,
                    'finished_at' => now()->toIso8601String(),
                ]);

                return;
            }

            // Real import done. Refresh coverage if node is reachable here.
            $coverage = $this->refreshCoverage();

            $packages->writeRun($this->run, [
                'status' => 'imported',
                'report' => $report,
                'coverage_refresh' => $coverage,
                'finished_at' => now()->toIso8601String(),
            ]);

            // One audit entry: locale, counts, operator (WF-SYS-03).
            $audit->append(
                module: 'i18n',
                event: 'language_package.imported',
                payload: [
                    'run' => $this->run,
                    'locale' => $this->locale,
                    'accepted' => $report['accepted'],
                    'rejected' => $report['rejected'],
                    'files' => $report['files'],
                    'coverage_refresh' => $coverage,
                ],
                ref: 'WF-SYS-03',
                actorId: $this->actorId,
            );
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

    /**
     * node scripts/i18n/check.mjs --warn-only, run only when node is on PATH
     * (the fc_vite container). Absent here, the refresh is deferred, never a
     * failure. Returns a short status the card and the desk both read.
     */
    private function refreshCoverage(): string
    {
        try {
            $check = new Process(
                ['node', base_path(LanguagePackageService::CHECK_SCRIPT), '--warn-only'],
                base_path(),
            );
            $check->setTimeout(600);
            $check->run();

            return $check->isSuccessful() ? 'coverage refreshed' : 'coverage refresh failed';
        } catch (\Throwable) {
            // node is not in this container: leave coverage.json for the desk.
            return 'coverage refresh pending';
        }
    }
}
