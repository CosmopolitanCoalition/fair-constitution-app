<?php

namespace Tests\Feature;

use App\Models\FederationPeer;
use App\Models\User;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\OperatorIdentityService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Phase G (G8b) — the operator's two-way-mesh console: the readiness GATES are public
 * mesh state, the SETUP/PROBE actions are auth:operator. The pins:
 *   1. GET /federation always carries the mesh-readiness gates (the operator's greens);
 *   2. an operator can probe a peer over the mesh from the GUI (the mesh:doctor front door);
 *   3. the mesh actions are refused without an operator session.
 *
 * Live-pg posture; an operator account stands in for the logged-in operator.
 */
class FederationMeshConsoleTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_mesh_console';

    public function test_the_console_carries_the_mesh_readiness_gates(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();

            $this->be($citizen, 'web')->get('/federation')
                ->assertOk()
                ->assertInertia(
                    fn (AssertableInertia $page) => $page
                        ->has('mesh.gates')
                        ->where('mesh.gates.0.key', 'federation_enabled'),
                );
        });
    }

    public function test_an_operator_probes_a_peer_over_the_mesh_from_the_gui(): void
    {
        $this->onLivePg(function () {
            $op = $this->operator();
            Http::fake(['*peer-x.test*' => Http::response(['server_id' => 'peer-x'], 200)]);

            $this->actingAs($op, 'operator')
                ->post('/federation/mesh/probe', ['target' => 'https://peer-x.test'])
                ->assertRedirect();

            $probe = session('mesh_probe');
            $this->assertNotNull($probe, 'the probe result is flashed back to the console');
            $this->assertSame(1, $probe['reached']);
            $this->assertSame('https://peer-x.test', $probe['target']);
        });
    }

    public function test_mesh_actions_are_refused_without_an_operator_session(): void
    {
        $this->onLivePg(function () {
            $before = FederationPeer::query()->count();

            $this->post('/federation/mesh/discover', ['url' => 'https://intruder.test']);

            $this->assertGuest('operator');
            $this->assertSame($before, FederationPeer::query()->count(), 'an unauthenticated discover performs nothing');
        });
    }

    private function operator(): \App\Models\OperatorAccount
    {
        app(InstanceIdentityService::class)->ensureIdentity();

        return app(OperatorIdentityService::class)->register('meshop_'.Str::lower(Str::random(8)), 'correct horse battery');
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();
        $this->withoutMiddleware(ValidateCsrfToken::class);

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
