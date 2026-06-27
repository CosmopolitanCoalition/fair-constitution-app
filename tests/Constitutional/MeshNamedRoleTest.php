<?php

namespace Tests\Constitutional;

use App\Models\Jurisdiction;
use App\Services\Federation\CapabilityService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\MeshGateService;
use App\Services\Identity\MeshRoleOrchestrator;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the 4 named operator-roles (Operator Roles & Console ★1–★3/★11). A role is the SET
 * of capability channels it groups (config/mesh_roles.php); its STATE is DERIVED from those channels'
 * states (MeshGateService::roles()), never stored. Adopting a role batches the per-channel flow
 * (self-asserted establish + governed request) through the IDENTICAL substrate; dropping revokes — but
 * never a channel a still-established sibling role needs.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class MeshNamedRoleTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_namedrole';

    public function test_the_catalog_maps_the_four_roles_to_their_channel_sets(): void
    {
        $catalog = (array) config('mesh_roles');

        $this->assertSame(
            ['record_keeper', 'archivist', 'social_moderator', 'identity_broker'],
            array_keys($catalog),
        );
        $this->assertSame(['mirror', 'etl'], $catalog['record_keeper']['channels']);
        $this->assertSame(['client.serve'], $catalog['archivist']['channels']);
        $this->assertSame('read_write', $catalog['archivist']['petition']); // Art. V §7, not a channel
        $this->assertSame(['matrix.homeserver', 'voice.sfu', 'client.serve'], $catalog['social_moderator']['channels']);
        $this->assertTrue($catalog['record_keeper']['recommended'], 'Record Keeper is the first-node default');
    }

    public function test_role_state_is_derived_from_its_channels(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            config(['services.cloudflare.dns_token' => '', 'cga.broker.domains' => []]);
            $caps = app(CapabilityService::class);

            $recordKeeper = fn () => collect(app(MeshGateService::class)->roles())->firstWhere('role', 'record_keeper');

            $caps->registerSelf('mirror'); // one of two channels established
            $this->assertSame(MeshGateService::ROLE_PARTIAL, $recordKeeper()['state'], 'some-but-not-all → partial');

            $caps->registerSelf('etl');
            $this->assertSame(MeshGateService::ROLE_ESTABLISHED, $recordKeeper()['state'], 'every channel → established');
        });
    }

    public function test_adopt_establishes_self_asserted_and_requests_governed(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $scope = $this->jurisdiction();
            $caps = app(CapabilityService::class);

            // Record Keeper = {mirror, etl}, both self-asserted → established immediately (one-click).
            app(MeshRoleOrchestrator::class)->adopt('record_keeper', $scope->id);
            $rk = collect(app(MeshGateService::class)->roles($scope->id))->firstWhere('role', 'record_keeper');
            $this->assertSame(MeshGateService::ROLE_ESTABLISHED, $rk['state']);
            $this->assertTrue($caps->holds($identity->serverId(), 'mirror'));
            $this->assertTrue($caps->holds($identity->serverId(), 'etl'));

            // A GOVERNED channel → adopt opens a grant REQUEST (awaits dual-meter consent), never established.
            config(['matrix.homeserver_url' => 'http://synapse:8008']);
            $result = app(MeshRoleOrchestrator::class)->adopt('social_moderator', $scope->id);
            $byCap = collect($result['actions'])->keyBy('capability');
            $this->assertSame('governed', $byCap['matrix.homeserver']['kind']);
            $this->assertSame('requested', $byCap['matrix.homeserver']['result']);
            $this->assertFalse($caps->holds($identity->serverId(), 'matrix.homeserver'), 'governed is not self-established');
        });
    }

    public function test_drop_keeps_a_channel_shared_with_a_still_established_role(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $caps = app(CapabilityService::class);

            // Grant client.serve so Archivist ({client.serve}) is fully established.
            $caps->grantSelf('client.serve', $identity->serverId(), 'test-sig', null);
            $this->assertTrue($caps->holds($identity->serverId(), 'client.serve'));

            // Dropping Social Moderator (which also lists client.serve) must NOT pull it from Archivist.
            $result = app(MeshRoleOrchestrator::class)->drop('social_moderator');

            $this->assertContains('client.serve', $result['kept_shared']);
            $this->assertTrue(
                $caps->holds($identity->serverId(), 'client.serve'),
                'client.serve survives the drop — a still-established sibling role needs it',
            );
        });
    }

    private function jurisdiction(): Jurisdiction
    {
        $j = new Jurisdiction();
        $j->forceFill([
            'id' => (string) Str::uuid(), 'name' => 'Role '.Str::random(6),
            'slug' => 'role-'.Str::lower(Str::random(12)), 'adm_level' => 5,
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
