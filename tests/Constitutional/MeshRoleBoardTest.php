<?php

namespace Tests\Constitutional;

use App\Models\InstanceCapability;
use App\Models\Jurisdiction;
use App\Services\Federation\CapabilityService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\MeshGateService;
use App\Services\Identity\MeshRoleGrantService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Mesh Roles & Channels of Trust (★13/★16), the Role Board. The flat readiness view
 * re-projected as channel-keyed clusters: one entry per channel, each carrying a state (established /
 * qualifiable / needs-config / requested / lapsed) + a self-contained gate cluster. The broker.* channels
 * fold in the broker-readiness gates. `mesh:gates` (the flat evaluate()) is unchanged.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class MeshRoleBoardTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_roleboard';

    public function test_the_role_board_derives_a_state_per_channel(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            config(['services.cloudflare.dns_token' => '', 'cga.broker.domains' => []]);
            $scope = $this->jurisdiction();

            $caps = app(CapabilityService::class);
            $caps->registerSelf('mesh.member'); // established

            config(['matrix.homeserver_url' => 'http://synapse:8008']);
            app(MeshRoleGrantService::class)->request('matrix.homeserver', $scope->id); // requested

            $board = collect(app(MeshGateService::class)->channels($scope->id))->keyBy('capability');

            $this->assertCount(count(InstanceCapability::CHANNELS), $board, 'one Role Board entry per channel');
            $this->assertSame(MeshGateService::STATE_ESTABLISHED, $board['mesh.member']['state']);
            $this->assertSame(MeshGateService::STATE_REQUESTED, $board['matrix.homeserver']['state']);
            // broker.dns has no Cloudflare token + no homeserver-style config ⇒ needs-config.
            $this->assertSame(MeshGateService::STATE_NEEDS_CONFIG, $board['broker.dns']['state']);
            $this->assertTrue($board['authority.grant']['affects_peer_subtree']);

            // The broker.tls cluster folds in the broker-readiness gates (more than just qualify).
            $this->assertGreaterThan(1, count($board['broker.tls']['gates']), 'broker.tls carries broker-readiness gates');
        });
    }

    private function jurisdiction(): Jurisdiction
    {
        $j = new Jurisdiction();
        $j->forceFill([
            'id' => (string) Str::uuid(), 'name' => 'Board '.Str::random(6),
            'slug' => 'board-'.Str::lower(Str::random(12)), 'adm_level' => 5,
            'parent_id' => null, 'source' => 'user_defined',
        ])->save();

        return $j;
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        app(RoleService::class)->flush();
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            app(RoleService::class)->flush();
        }
    }
}
