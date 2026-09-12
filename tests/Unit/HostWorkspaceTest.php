<?php

namespace Tests\Unit;

use App\Http\Controllers\Operator\MeshConsoleController;
use App\Models\OperatorAccount;
use App\Services\Federation\MeshGateService;
use App\Services\PeerUpgradeAgreementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

/** Controller read contracts on explicitly guarded SQLite memory fixtures only. */
final class HostWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.host_workspace_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('host_workspace_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    public function test_citizen_overview_never_reads_or_exposes_host_data(): void
    {
        Auth::shouldReceive('guard')->once()->with('operator')->andReturn(
            \Mockery::mock()->shouldReceive('user')->once()->andReturnNull()->getMock()
        );
        $gates = $this->createMock(MeshGateService::class);
        $gates->expects($this->never())->method('evaluate');
        $gates->expects($this->never())->method('roles');
        $gates->expects($this->never())->method('channels');
        DB::enableQueryLog();

        $response = (new MeshConsoleController)->home($gates, $this->createMock(PeerUpgradeAgreementService::class));
        $props = (new ReflectionProperty($response, 'props'))->getValue($response);

        self::assertFalse($props['authed']);
        self::assertNull($props['operator']);
        self::assertNull($props['console']);
        self::assertSame('Operator/Home', (new ReflectionProperty($response, 'component'))->getValue($response));
        self::assertSame([], DB::getQueryLog(), 'The sign-in shell must not read any host or world tables.');
    }

    public function test_old_console_entrance_redirects_without_data_reads(): void
    {
        DB::enableQueryLog();
        $response = (new MeshConsoleController)->console();
        self::assertSame(route('operator.home'), $response->getTargetUrl());
        self::assertSame([], DB::getQueryLog());
    }

    public function test_operator_overview_uses_service_snapshots_without_whole_world_counts(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jurisdictions', function (Blueprint $table) {
            $table->string('id');
            $table->string('parent_id')->nullable();
            $table->softDeletes();
        });
        $schema->create('operator_accounts', function (Blueprint $table) {
            $table->string('id');
            $table->string('status');
            $table->softDeletes();
        });
        $schema->create('peer_upgrade_proposals', function (Blueprint $table) {
            $table->string('id');
            $table->string('kind');
            $table->string('status');
            $table->softDeletes();
        });
        DB::table('jurisdictions')->insert([
            ['id' => 'root', 'parent_id' => null],
            ['id' => 'child', 'parent_id' => 'root'],
        ]);
        DB::table('operator_accounts')->insert(['id' => 'operator', 'status' => 'active']);
        DB::table('peer_upgrade_proposals')->insert(['id' => 'proposal', 'kind' => 'role_grant', 'status' => 'open']);
        $operator = new OperatorAccount(['username' => 'Host operator']);
        Auth::shouldReceive('guard')->once()->with('operator')->andReturn(
            \Mockery::mock()->shouldReceive('user')->once()->andReturn($operator)->getMock()
        );
        $gates = $this->createMock(MeshGateService::class);
        $gates->expects($this->once())->method('evaluate')->willReturn([['key' => 'identity', 'status' => 'pass']]);
        $gates->expects($this->once())->method('roles')->with('root')->willReturn([['role' => 'relay', 'state' => 'established']]);
        $gates->expects($this->once())->method('channels')->with('root')->willReturn([['capability' => 'mirror']]);
        $upgrades = $this->createMock(PeerUpgradeAgreementService::class);
        $upgrades->expects($this->once())->method('applicableConsentLeg')->with('root')->willReturn('operator');
        $upgrades->expects($this->once())->method('coAffectedPeerServerIds')->with('root')->willReturn([]);
        DB::enableQueryLog();

        $response = (new MeshConsoleController)->home($gates, $upgrades);
        $props = (new ReflectionProperty($response, 'props'))->getValue($response);

        self::assertTrue($props['authed']);
        self::assertSame('Host operator', $props['operator']);
        self::assertSame('established', $props['console']['roles'][0]['state']);
        self::assertSame(1, $props['console']['meters']['open_proposals']['total']);
        self::assertArrayNotHasKey('readiness', $props, 'The old duplicate world summary is retired.');
        $worldReads = array_values(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'jurisdictions')));
        self::assertCount(1, $worldReads);
        self::assertStringContainsString('"parent_id" is null', $worldReads[0]['query']);
        self::assertStringNotContainsString('count(', $worldReads[0]['query']);
    }
}
