<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * W-0446 wiring pin, DB-free (operator ruling 2026-09-15).
 *
 * Holds the invariants the lane exists to guarantee:
 *   - every package route is registered under the system prefix;
 *   - the controller gates every action operator-only and NEVER runs a script;
 *   - the scripts run only in the queued jobs;
 *   - the import job writes exactly one audit entry.
 */
class Wiring_w0446_language_packagesTest extends TestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(base_path($rel));
    }

    public function test_every_package_route_is_registered(): void
    {
        foreach ([
            'system.translations.packages',
            'system.translations.packages.export',
            'system.translations.packages.import',
            'system.translations.packages.confirm',
            'system.translations.packages.retry',
            'system.translations.packages.discard',
            'system.translations.packages.download',
            'system.translations.languages.request',
        ] as $name) {
            $this->assertTrue(Route::has($name), "route {$name} is registered");
        }
    }

    public function test_the_controller_gates_operator_only_and_runs_no_script(): void
    {
        $ctrl = $this->read('app/Http/Controllers/System/TranslationPackageController.php');

        // Operator gate, the halt/resume shape.
        $this->assertStringContainsString('is_operator', $ctrl);
        $this->assertStringContainsString('abort_unless', $ctrl);
        // Every public action calls the gate.
        $this->assertSame(8, substr_count($ctrl, '$this->operatorOnly($request)'));
        // The web request never executes a script or a raw process.
        $this->assertStringNotContainsString('Symfony\\Component\\Process', $ctrl);
        $this->assertStringNotContainsString('proc_open', $ctrl);
        // It dispatches the queued jobs instead.
        $this->assertStringContainsString('ExportLanguagePackageJob::dispatch', $ctrl);
        $this->assertStringContainsString('ImportLanguagePackageJob::dispatch', $ctrl);
    }

    public function test_the_jobs_run_the_scripts(): void
    {
        $export = $this->read('app/Jobs/I18n/ExportLanguagePackageJob.php');
        $import = $this->read('app/Jobs/I18n/ImportLanguagePackageJob.php');

        $this->assertStringContainsString('ShouldQueue', $export);
        $this->assertStringContainsString('Symfony\\Component\\Process\\Process', $export);
        $this->assertStringContainsString('ShouldQueue', $import);
        $this->assertStringContainsString('Symfony\\Component\\Process\\Process', $import);
        // Coverage refresh is attempted through node, deferred when unreachable.
        $this->assertStringContainsString('check.mjs', $import);
        $this->assertStringContainsString('coverage refresh pending', $import);
    }

    public function test_the_jobs_ride_the_long_lane_and_close_their_record_on_failure(): void
    {
        // The 60 s default lane killed the first Hindi export (2026-09-15).
        $export = new \App\Jobs\I18n\ExportLanguagePackageJob('run-x', 'hi');
        $this->assertSame('redis-long', $export->connection);
        $this->assertSame('long-running', $export->queue);
        $this->assertSame(1800, $export->timeout);

        $import = new \App\Jobs\I18n\ImportLanguagePackageJob('run-y');
        $this->assertSame('redis-long', $import->connection);
        $this->assertSame('long-running', $import->queue);
        $this->assertSame(1800, $import->timeout);

        foreach (['app/Jobs/I18n/ExportLanguagePackageJob.php', 'app/Jobs/I18n/ImportLanguagePackageJob.php'] as $rel) {
            $src = $this->read($rel);
            $this->assertStringContainsString('public function failed(', $src, "{$rel} closes its run record on failure");
            $this->assertStringContainsString("'status' => 'failed'", $src);
        }

        // Retry and Discard are never blocked by the state they recover from.
        $ctrl = $this->read('app/Http/Controllers/System/TranslationPackageController.php');
        $this->assertStringContainsString('public function retry(', $ctrl);
        $this->assertStringContainsString('public function discard(', $ctrl);
    }

    public function test_the_export_script_never_walks_the_source_tree(): void
    {
        $py = $this->read('scripts/i18n/export_master.py');
        $this->assertStringNotContainsString('rglob(', $py, 'the export is JSON arithmetic, never a walk of resources/js');
        $this->assertStringNotContainsString('read_text(encoding="utf-8", errors="ignore")', $py);
    }

    public function test_every_language_package_has_one_layout_english_included(): void
    {
        // Operator order 2026-09-15: the English master and a target package
        // share one hierarchy. One export door (the script), no staging copy in PHP.
        $export = $this->read('app/Jobs/I18n/ExportLanguagePackageJob.php');
        $this->assertStringNotContainsString('stageSourceMaster', $export);
        $this->assertStringNotContainsString('isSourceLocale', $export, 'the export job never branches on the locale');
        $this->assertSame(1, substr_count($export, '$packages->exportCommand('));

        $svc = $this->read('app/Services/I18n/LanguagePackageService.php');
        $this->assertStringNotContainsString('stageSourceMaster', $svc);
        $this->assertStringNotContainsString('file_put_contents($dir', $svc, 'PHP never writes package files; the script does');

        // The tree: README.txt, ui/<namespace>.json, php/<code>.json, every file complete.
        $py = $this->read('scripts/i18n/export_master.py');
        $this->assertStringContainsString('SOURCE_LOCALE = "en"', $py);
        $this->assertStringContainsString('out_dir / "ui" / f"{ns}.json"', $py);
        $this->assertStringContainsString('out_dir / "php" / f"{locale}.json"', $py);
        $this->assertStringContainsString('"README.txt"', $py);
        $this->assertStringNotContainsString('--chunk', $py, 'chunk files are retired');

        // The importer reads the same tree and names the locale the operator chose.
        $imp = $this->read('scripts/i18n/import_translated.py');
        $this->assertStringContainsString('if parent == "ui":', $imp);
        $this->assertStringContainsString('if parent == "php":', $imp);
        $job = $this->read('app/Jobs/I18n/ImportLanguagePackageJob.php');
        $this->assertStringContainsString('locale: $this->locale', $job);
        // A zip upload is unpacked inside the run before the importer walks it (defect 2026-09-15).
        $this->assertStringContainsString('$packages->unpackUpload($target)', $job);
        $this->assertLessThan(strpos($job, '$packages->importCommand('), strpos($job, '$packages->unpackUpload('), 'unpack comes before the importer');
    }

    public function test_the_import_job_writes_one_audit_entry(): void
    {
        $import = $this->read('app/Jobs/I18n/ImportLanguagePackageJob.php');

        $this->assertSame(1, substr_count($import, '$audit->append('));
        $this->assertStringContainsString("event: 'language_package.imported'", $import);
        $this->assertStringContainsString("'locale' =>", $import);
        $this->assertStringContainsString("'accepted' =>", $import);
    }
}
