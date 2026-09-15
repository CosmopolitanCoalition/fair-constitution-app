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
            'en' => ['target' => true],
            'es' => ['target' => true],
            'xx' => ['target' => false],
        ]);
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
