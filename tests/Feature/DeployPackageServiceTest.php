<?php

namespace Tests\Feature;

use App\Services\Setup\DeployPackageService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Distributable deploy-script package (Workstream B). Pins:
 *   - all four (os x kind) renders produce a script with the right filename;
 *   - a SOLO package carries NO secret (no APP_KEY, DB creds, or private key) and
 *     reaches its own /setup;
 *   - a JOIN package bakes THIS box's self-URL as the host + a real join key;
 *   - a JOIN render with no self-URL set throws a clear, actionable message.
 *
 * The join renders mint a real key (DB write + audit append), so they run under
 * the live-pg posture and SKIP when Postgres is unreachable.
 */
class DeployPackageServiceTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_deploy_package';

    /** Substrings that would betray a secret leaking into a shared script. */
    private const SECRET_MARKERS = ['APP_KEY', 'base64:', 'PRIVATE KEY', 'DB_PASSWORD', 'fc_password'];

    public function test_solo_renders_for_both_os_without_any_secret(): void
    {
        $svc = app(DeployPackageService::class);

        $cases = [
            ['unix', 'cga-solo-unix.sh'],
            ['windows', 'cga-solo-windows.ps1'],
        ];

        foreach ($cases as [$os, $expectedFilename]) {
            $script = $svc->render($os, 'solo');

            $this->assertSame($expectedFilename, $script['filename']);
            $this->assertNotSame('', trim($script['body']));
            // A fresh solo run must land on its OWN setup page.
            $this->assertStringContainsString('/setup', $script['body']);
            // The deploy invocation must sit on its OWN line — a missing newline
            // would glue it onto the preceding say/Say string and break the script.
            $this->assertMatchesRegularExpression(
                '/^\.\/deploy\.(sh|ps1)\b/m',
                $script['body'],
                "solo/{$os} must invoke deploy on its own line"
            );

            foreach (self::SECRET_MARKERS as $marker) {
                $this->assertStringNotContainsString(
                    $marker,
                    $script['body'],
                    "solo/{$os} must not leak a secret marker: {$marker}"
                );
            }
        }
    }

    public function test_windows_scripts_are_pure_ascii(): void
    {
        $svc = app(DeployPackageService::class);

        $body = $svc->render('windows', 'solo')['body'];

        $this->assertSame(
            0,
            preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $body),
            'a .ps1 must be pure-ASCII — a stray multibyte byte breaks PowerShell 5.1 parsing'
        );
    }

    public function test_a_join_render_with_no_self_url_throws_a_clear_message(): void
    {
        config(['cga.federation_self_url' => null]);

        $svc = app(DeployPackageService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set your peer-reachable address');

        $svc->render('unix', 'join');
    }

    public function test_join_bakes_the_host_self_url_and_a_key_for_both_os(): void
    {
        $this->onLivePg(function () {
            $selfUrl = 'http://box-a.example:8080';
            config(['cga.federation_self_url' => $selfUrl]);

            $svc = app(DeployPackageService::class);

            $cases = [
                ['unix', 'cga-join-unix.sh'],
                ['windows', 'cga-join-windows.ps1'],
            ];

            foreach ($cases as [$os, $expectedFilename]) {
                $script = $svc->render($os, 'join');

                $this->assertSame($expectedFilename, $script['filename']);
                // The host to join is baked in.
                $this->assertStringContainsString($selfUrl, $script['body'], "join/{$os} must name the host self-URL");
                // A join key (handle.secret) is baked in — the join flow's --key/-Key.
                $this->assertMatchesRegularExpression(
                    '/[a-z0-9]{12}\.[0-9a-f]{64}/',
                    $script['body'],
                    "join/{$os} must carry a minted join key (handle.secret)"
                );
                // The script drives the join flow, on its own line.
                $flag = $os === 'windows' ? '-Join' : '--join';
                $this->assertStringContainsString($flag, $script['body']);
                $this->assertMatchesRegularExpression(
                    '/^\.\/deploy\.(sh|ps1)\b.*'.preg_quote($flag, '/').'/m',
                    $script['body'],
                    "join/{$os} must invoke deploy --join on its own line"
                );
            }
        });
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
