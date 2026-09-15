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
        $this->assertSame(6, substr_count($ctrl, '$this->operatorOnly($request)'));
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

    public function test_the_import_job_writes_one_audit_entry(): void
    {
        $import = $this->read('app/Jobs/I18n/ImportLanguagePackageJob.php');

        $this->assertSame(1, substr_count($import, '$audit->append('));
        $this->assertStringContainsString("event: 'language_package.imported'", $import);
        $this->assertStringContainsString("'locale' =>", $import);
        $this->assertStringContainsString("'accepted' =>", $import);
    }
}
