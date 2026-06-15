<?php

namespace Tests\Constitutional;

use App\Http\Controllers\Federation\FederationConsoleController;
use App\Models\InstanceSettings;
use App\Services\Mirror\MirrorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G3b "Join a cluster" wizard). The browser path to
 * mirror adoption, over the SAME MirrorService the CLI uses. The pins:
 *  1. the join / leave routes are registered;
 *  2. leave() flips this instance out of read-only-mirror mode;
 *  3. join() validates its input (a host URL is required);
 *  4. leave() on a non-mirror is a no-op (it never silently corrupts state).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class ClusterJoinSurfaceTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_cluster_join';

    public function test_the_join_and_leave_routes_are_registered(): void
    {
        $this->assertTrue(Route::has('federation.cluster.join'));
        $this->assertTrue(Route::has('federation.cluster.leave'));
    }

    public function test_leave_flips_the_instance_out_of_mirror_mode(): void
    {
        $this->onLivePg(function () {
            // Become a read-only mirror.
            $settings = InstanceSettings::current();
            $settings->mirror_of_server_id = (string) Str::uuid();
            $settings->mirror_adopted_at = now();
            $settings->save();
            $this->assertTrue(InstanceSettings::current()->isMirror());

            $response = app(FederationConsoleController::class)->leave(app(MirrorService::class));

            $this->assertInstanceOf(RedirectResponse::class, $response);
            $this->assertFalse(InstanceSettings::current()->isMirror(), 'leave() stops being a mirror');
        });
    }

    public function test_join_requires_a_host_url(): void
    {
        $this->onLivePg(function () {
            $request = Request::create('/federation/cluster/join', 'POST', []);

            $threw = false;
            try {
                app(FederationConsoleController::class)->join($request, app(MirrorService::class));
            } catch (ValidationException $e) {
                $threw = true;
                $this->assertArrayHasKey('host_url', $e->errors());
            }
            $this->assertTrue($threw, 'join() requires a host URL');
        });
    }

    public function test_leave_on_a_non_mirror_is_a_safe_no_op(): void
    {
        $this->onLivePg(function () {
            $settings = InstanceSettings::current();
            $settings->mirror_of_server_id = null;
            $settings->save();
            $this->assertFalse(InstanceSettings::current()->isMirror());

            $response = app(FederationConsoleController::class)->leave(app(MirrorService::class));

            $this->assertInstanceOf(RedirectResponse::class, $response);
            $this->assertFalse(InstanceSettings::current()->isMirror());
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
