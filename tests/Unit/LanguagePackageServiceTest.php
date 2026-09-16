<?php

namespace Tests\Unit;

use App\Services\I18n\LanguagePackageService;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * W-0446 — LanguagePackageService, DB-free (operator ruling 2026-09-15).
 *
 * The service is pure: it shapes commands and paths and parses the importer's
 * report. No database, no process, no network. These pins hold that contract.
 */
class LanguagePackageServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/i18n-packages-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $this->rrmdir($this->tmp);
        }
        parent::tearDown();
    }

    private function svc(): LanguagePackageService
    {
        return new LanguagePackageService($this->tmp, [
            'en' => ['name' => 'English', 'endonym' => 'English', 'target' => true],
            'es' => ['name' => 'Spanish', 'endonym' => 'español', 'target' => true],
            'pl' => ['name' => 'Polish', 'endonym' => 'polski', 'target' => true],
            'xx' => ['target' => false],
        ]);
    }

    public function test_english_is_the_source_exportable_as_a_master_never_a_package_target(): void
    {
        $svc = $this->svc();

        $this->assertTrue($svc->isSourceLocale('en'));
        $this->assertFalse($svc->isSourceLocale('es'));
        $this->assertTrue($svc->isExportable('en'));
        $this->assertTrue($svc->isExportable('es'));
        $this->assertFalse($svc->isExportable('xx'));
        $this->assertNotContains('en', $svc->targetLocales());

        // The script command is for targets only; the master is staged, not scripted.
        $this->expectException(InvalidArgumentException::class);
        $svc->exportCommand('en', 'run-1');
    }

    public function test_language_rows_state_what_is_present_and_what_is_not(): void
    {
        $rows = $this->svc()->languageRows([
            'source_keys' => 10,
            'locales' => [['locale' => 'es', 'pct' => 28.9, 'missing' => 7]],
        ]);

        $this->assertSame(['es', 'pl'], array_column($rows, 'code'), 'present first, then by name');
        $this->assertSame(['Spanish', 'Polish'], array_column($rows, 'name'));
        $this->assertTrue($rows[0]['present']);
        $this->assertSame(28.9, $rows[0]['pct']);
        $this->assertSame(7, $rows[0]['missing']);
        $this->assertFalse($rows[1]['present']);
        $this->assertNull($rows[1]['pct']);
        $this->assertNull($rows[1]['missing']);

        // Never measured: every target is absent, English is still not a row.
        $unmeasured = $this->svc()->languageRows(null);
        $this->assertSame(['pl', 'es'], array_column($unmeasured, 'code'), 'by name when nothing is present');
        $this->assertSame([false, false], array_column($unmeasured, 'present'));
    }

    public function test_the_english_master_stages_the_source_catalogues_and_a_readme(): void
    {
        $repo = $this->tmp . '/repo';
        mkdir($repo . '/resources/js/i18n/locales/en', 0775, true);
        mkdir($repo . '/lang', 0775, true);
        file_put_contents($repo . '/resources/js/i18n/locales/en/auth.json', json_encode(['a' => 'A', 'b' => 'B']));
        file_put_contents($repo . '/resources/js/i18n/locales/en/civic.json', json_encode(['c' => 'C']));
        file_put_contents($repo . '/lang/en.json', json_encode(['Line' => 'Line']));

        $svc = $this->svc();
        $files = $svc->sourceFiles($repo);
        $this->assertSame(['ui/auth.json', 'ui/civic.json', 'php/en.json'], array_keys($files));

        $count = $svc->stageSourceMaster('run-en', $repo);
        $this->assertSame(3, $count);

        $dir = $svc->exportDir('run-en') . '/en';
        $this->assertFileExists($dir . '/ui/auth.json');
        $this->assertFileExists($dir . '/ui/civic.json');
        $this->assertFileExists($dir . '/php/en.json');
        $this->assertFileExists($dir . '/README.txt');
        $readme = (string) file_get_contents($dir . '/README.txt');
        $this->assertStringContainsString('Strings: 4 across 3 files.', $readme);
        $this->assertStringContainsString('the 2 Vue namespace catalogues', $readme);

        // Zipped like any package: the master lands as en-package.zip.
        if (class_exists(\ZipArchive::class)) {
            $n = $svc->zipDir($dir, $svc->packageZipPath('run-en', 'en'));
            $this->assertSame(4, $n);
        }
    }

    public function test_it_builds_the_export_command_and_zip_path(): void
    {
        $svc = $this->svc();
        $cmd = $svc->exportCommand('es', 'run-1');

        $this->assertSame('python3', $cmd[0]);
        $this->assertStringEndsWith('scripts/i18n/export_master.py', str_replace('\\', '/', $cmd[1]));
        $this->assertContains('--locale', $cmd);
        $this->assertContains('es', $cmd);
        $this->assertContains('--out', $cmd);

        $out = $cmd[array_search('--out', $cmd, true) + 1];
        $this->assertStringEndsWith('run-1/export', str_replace('\\', '/', $out));

        $zip = $svc->packageZipPath('run-1', 'es');
        $this->assertStringEndsWith('run-1/es-package.zip', str_replace('\\', '/', $zip));
    }

    public function test_it_parses_a_dry_run_report(): void
    {
        $stdout = <<<'TXT'
import 1 file(s)  [DRY RUN]
  es_auth.json: 2 accepted, 1 rejected  (es/auth)

  rejections:
    es/auth  auth_login.title: dropped placeholder {name}

  files 1   accepted 2   rejected 1   [DRY RUN - nothing written]
TXT;

        $report = $this->svc()->parseDryRunReport($stdout);

        $this->assertSame(2, $report['accepted']);
        $this->assertSame(1, $report['rejected']);
        $this->assertSame(1, $report['files']);
        $this->assertCount(1, $report['rejections']);
        $this->assertSame('es', $report['rejections'][0]['locale']);
        $this->assertSame('auth', $report['rejections'][0]['namespace']);
        $this->assertSame('auth_login.title', $report['rejections'][0]['key']);
        $this->assertSame('dropped placeholder {name}', $report['rejections'][0]['reason']);
    }

    public function test_it_refuses_a_non_target_locale(): void
    {
        $this->assertTrue($this->svc()->isTargetLocale('es'));
        $this->assertFalse($this->svc()->isTargetLocale('xx'));

        $this->expectException(InvalidArgumentException::class);
        $this->svc()->exportCommand('xx', 'run-1');
    }

    public function test_it_refuses_a_path_outside_the_store(): void
    {
        $svc = $this->svc();

        // A run id inside the store resolves.
        $this->assertStringEndsWith('/run-1', str_replace('\\', '/', $svc->runDir('run-1')));

        $this->expectException(InvalidArgumentException::class);
        $svc->pathWithin('../../etc/passwd');
    }

    public function test_a_traversal_run_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc()->runDir('../evil');
    }

    public function test_a_stale_run_is_an_in_flight_record_nobody_updated(): void
    {
        $svc = $this->svc();
        $now = 1_800_000_000;

        $fresh = ['status' => 'exporting', 'updated_at' => date(DATE_ATOM, $now - 60)];
        $old = ['status' => 'exporting', 'updated_at' => date(DATE_ATOM, $now - 4000)];
        $done = ['status' => 'ready', 'updated_at' => date(DATE_ATOM, $now - 4000)];
        $failed = ['status' => 'failed', 'updated_at' => date(DATE_ATOM, $now - 4000)];

        $this->assertFalse($svc->isStale($fresh, $now));
        $this->assertTrue($svc->isStale($old, $now));
        $this->assertFalse($svc->isStale($done, $now), 'a finished run is never stale');
        $this->assertFalse($svc->isStale($failed, $now), 'a failed run is recoverable, not stale');

        $svc->writeRun('run-old', ['kind' => 'export', 'locale' => 'es', 'status' => 'exporting']);
        $rows = $svc->listRuns($now + 10_000_000);
        $this->assertTrue($rows[0]['stale']);
        $this->assertFalse($svc->listRuns()[0]['stale'], 'just written, not stale now');
    }

    public function test_discard_removes_the_run_and_refuses_traversal(): void
    {
        $svc = $this->svc();
        $svc->writeRun('run-d', ['kind' => 'export', 'locale' => 'es', 'status' => 'failed']);
        $svc->ensureDir($svc->exportDir('run-d') . '/es');
        file_put_contents($svc->exportDir('run-d') . '/es/auth.json', '{}');

        $this->assertTrue($svc->discardRun('run-d'));
        $this->assertDirectoryDoesNotExist($svc->runDir('run-d'));
        $this->assertFalse($svc->discardRun('run-d'), 'gone is gone');
        $this->assertSame([], $svc->listRuns());

        $this->expectException(InvalidArgumentException::class);
        $svc->discardRun('../evil');
    }

    public function test_requests_round_trip_in_the_store(): void
    {
        $svc = $this->svc();
        $this->assertSame([], $svc->listRequests());

        $svc->recordRequest('Klingon (tlh)', 'operator-1', 'conference ask');
        $list = $svc->listRequests();

        $this->assertCount(1, $list);
        $this->assertSame('Klingon (tlh)', $list[0]['locale']);
        $this->assertSame('operator-1', $list[0]['requester']);
        $this->assertSame('conference ask', $list[0]['note']);
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
