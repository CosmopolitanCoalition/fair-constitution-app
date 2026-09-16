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

        // One door: the master is the same script for locale en, same layout.
        $cmd = $svc->exportCommand('en', 'run-1');
        $this->assertContains('--locale', $cmd);
        $this->assertSame('en', $cmd[array_search('--locale', $cmd, true) + 1]);
        $this->assertStringEndsWith('run-1/en-package.zip', str_replace('\\', '/', $svc->packageZipPath('run-1', 'en')));
        $this->assertFalse(method_exists($svc, 'stageSourceMaster'), 'no second layout for the master');

        $this->expectException(InvalidArgumentException::class);
        $svc->exportCommand('xx', 'run-1');
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

    public function test_source_files_are_counted_for_the_card(): void
    {
        $repo = $this->tmp . '/repo';
        mkdir($repo . '/resources/js/i18n/locales/en', 0775, true);
        mkdir($repo . '/lang', 0775, true);
        file_put_contents($repo . '/resources/js/i18n/locales/en/auth.json', '{"a":"A"}');
        file_put_contents($repo . '/resources/js/i18n/locales/en/civic.json', '{"c":"C"}');
        file_put_contents($repo . '/lang/en.json', '{"Line":"Line"}');

        $files = $this->svc()->sourceFiles($repo);
        $this->assertSame(['ui/auth.json', 'ui/civic.json', 'php/en.json'], array_keys($files));
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
