<?php

namespace Tests\Constitutional;

use App\Models\InstanceSettings;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G8b) mesh:doctor. The survival-mesh self-test dials a
 * target FROM INSIDE the container so an operator learns whether an advertised transport
 * is actually routable (not just registered). The pins:
 *   1. with no target it reports this node's identity/federation/version (always succeeds);
 *   2. a reachable target over which the version AGREES is reported reached + version MATCH;
 *   3. a target unreachable over every transport exits non-zero (so a gate can detect it).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class MeshDoctorTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_mesh_doctor';

    public function test_no_target_reports_this_node_and_succeeds(): void
    {
        $this->onLivePg(function () {
            $serverId = app(InstanceIdentityService::class)->serverId();

            $this->artisan('mesh:doctor')
                ->expectsOutputToContain($serverId)
                ->assertSuccessful();
        });
    }

    public function test_a_reachable_versioned_target_is_reported_matched(): void
    {
        $this->onLivePg(function () {
            $ourCv = InstanceSettings::current()->constitutionalVersion();
            Http::fake(['*peer.test*' => Http::response([
                'server_id' => 'peer', 'constitutional_version' => $ourCv,
            ], 200)]);

            $this->artisan('mesh:doctor', ['target' => 'https://peer.test'])
                ->expectsOutputToContain('version MATCH')
                ->expectsOutputToContain('1/1 transport(s) reached')
                ->assertSuccessful();
        });
    }

    public function test_a_target_unreachable_over_every_transport_fails(): void
    {
        $this->onLivePg(function () {
            Http::fake(['*gone.test*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('vanished')]);

            $this->artisan('mesh:doctor', ['target' => 'https://gone.test'])
                ->expectsOutputToContain('UNREACHABLE')
                ->expectsOutputToContain('0/1 transport(s) reached')
                ->assertFailed();
        });
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            app(InstanceIdentityService::class)->ensureIdentity();
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
