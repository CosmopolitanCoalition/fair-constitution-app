<?php

namespace Tests\Unit;

use App\Models\AutoscaleRun;
use App\Models\InstanceSettings;
use App\Services\Setup\MapAcceptanceOptions;
use App\Services\Setup\MapAcceptanceResult;
use App\Services\Setup\MapAcceptanceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W-0285 — the ONE map-data acceptance path (MapAcceptanceService).
 *
 * The wizard endpoint, the maps:accept CLI command, and a backup restore must
 * leave identical state: map_accepted_at stamped, setup_step_completed >= 2,
 * the activation mode recorded, and (under eager) a mapping run created. Before
 * this service the CLI had no verifier gate, wrote no mode, and made a run with
 * the wrong status; the restore stamped only map_accepted_at.
 *
 * The service's state transitions are portable SQL, so this runs on a named
 * sqlite fixture. The world-build verifier is injected, so the eager gate is
 * exercised without the live PostgreSQL report.
 */
final class MapAcceptanceServiceTest extends TestCase
{
    private const CONNECTION = 'map_accept_fixture';

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection(self::CONNECTION);
        self::assertSame('sqlite', DB::connection()->getDriverName());

        $schema = DB::connection()->getSchemaBuilder();

        $schema->create('instance_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->timestamp('map_accepted_at')->nullable();
            $t->integer('setup_step_completed')->default(0);
            $t->string('institution_scale_mode')->nullable();
            $t->boolean('simulate_at_scale')->default(false);
            $t->string('game_mode')->nullable();
            $t->timestamp('apportionment_completed_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });

        $schema->create('autoscale_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('status')->default('queued');
            $t->integer('adm_max')->nullable();
            $t->uuid('initiator_user_id')->nullable();
            $t->string('template')->nullable();
            $t->integer('singles_total')->nullable();
            $t->integer('sweeps_total')->nullable();
            $t->timestamp('mapping_started_at')->nullable();
            $t->timestamp('halt_requested_at')->nullable();
            $t->timestamp('paused_until')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });

        $schema->create('jurisdictions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->integer('population')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });

        $schema->create('geodata_flags', function (Blueprint $t) {
            $t->increments('id');
            $t->string('status')->default('open');
            $t->string('severity')->default('info');
            $t->timestamp('deleted_at')->nullable();
        });

        $schema->create('apportionment_ledger', function (Blueprint $t) {
            $t->increments('id');
            $t->string('kind')->nullable();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::CONNECTION);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    // ---- helpers -----------------------------------------------------------

    private function seedInstance(array $overrides = []): void
    {
        DB::table('instance_settings')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'setup_step_completed' => 1,
            'simulate_at_scale' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function completeReport(): callable
    {
        return static fn (): array => ['complete' => true];
    }

    private function incompleteReport(): callable
    {
        return static fn (): array => [
            'complete' => false,
            'legislatures' => ['unsized_parents' => 3, 'unsized_leaves' => 7],
            'apportionment' => ['open' => 12, 'failed' => 1],
        ];
    }

    private function currentInstance(): InstanceSettings
    {
        return InstanceSettings::query()->whereNull('deleted_at')->firstOrFail();
    }

    // ---- eager: stamp, mode, mapping run -----------------------------------

    public function test_eager_acceptance_stamps_records_mode_and_creates_a_mapping_run(): void
    {
        $this->seedInstance();
        DB::table('apportionment_ledger')->insert([
            ['kind' => 'single'], ['kind' => 'single'], ['kind' => 'composite'],
        ]);

        $service = new MapAcceptanceService($this->completeReport());
        $result = $service->accept(new MapAcceptanceOptions(mode: 'eager', gateOnVerifier: true));

        self::assertSame(MapAcceptanceResult::ACCEPTED, $result->outcome);
        self::assertTrue($result->kickPump);
        self::assertNotNull($result->run);
        self::assertSame('mapping', $result->run->status);
        self::assertSame(2, (int) $result->run->singles_total);
        self::assertSame(1, (int) $result->run->sweeps_total);

        $instance = $this->currentInstance();
        self::assertNotNull($instance->map_accepted_at);
        self::assertGreaterThanOrEqual(2, (int) $instance->setup_step_completed);
        self::assertSame('eager', $instance->institution_scale_mode);
        self::assertSame(1, DB::table('autoscale_runs')->count());
    }

    // ---- the verifier gate (the debt: maps:accept had none) ----------------

    public function test_eager_refuses_when_the_world_build_is_incomplete(): void
    {
        $this->seedInstance();

        $service = new MapAcceptanceService($this->incompleteReport());
        $result = $service->accept(new MapAcceptanceOptions(mode: 'eager', gateOnVerifier: true));

        self::assertSame(MapAcceptanceResult::WORLD_BUILD_INCOMPLETE, $result->outcome);
        self::assertNull($result->run);
        self::assertFalse($result->kickPump);
        self::assertSame(3, $result->payload['progress']['legislatures']['unsized_parents']);

        // Nothing stamped, no run — the refusal is total.
        self::assertNull($this->currentInstance()->map_accepted_at);
        self::assertSame(0, DB::table('autoscale_runs')->count());
    }

    public function test_the_operator_can_force_past_an_incomplete_world_build(): void
    {
        // Operator order 2026-09-19: a gate the operator cannot pass is a wall.
        // With force, an incomplete build is accepted and the run starts; the
        // build keeps running in the background.
        $this->seedInstance();

        $service = new MapAcceptanceService($this->incompleteReport());
        $result = $service->accept(new MapAcceptanceOptions(
            mode: 'eager',
            gateOnVerifier: true,
            forceIncompleteBuild: true,
        ));

        self::assertSame(MapAcceptanceResult::ACCEPTED, $result->outcome);
        self::assertNotNull($this->currentInstance()->map_accepted_at);
        self::assertSame(1, DB::table('autoscale_runs')->count());
    }

    // ---- restore-style manual acceptance: mode written, no run -------------

    public function test_manual_restore_style_acceptance_stamps_mode_without_a_run(): void
    {
        $this->seedInstance();
        // An open critical flag would block a fresh accept, but a restore
        // acknowledges by construction and never runs the verifier.
        DB::table('geodata_flags')->insert(['status' => 'open', 'severity' => 'critical']);

        $service = new MapAcceptanceService($this->incompleteReport()); // never called: gate off
        $result = $service->accept(new MapAcceptanceOptions(
            mode: 'manual',
            acknowledgeOpenFlags: true,
            gateOnVerifier: false,
        ));

        self::assertSame(MapAcceptanceResult::ACCEPTED, $result->outcome);
        self::assertTrue($result->payload['deferred']);
        self::assertNull($result->run);
        self::assertFalse($result->kickPump);

        $instance = $this->currentInstance();
        self::assertNotNull($instance->map_accepted_at);
        self::assertGreaterThanOrEqual(2, (int) $instance->setup_step_completed);
        self::assertSame('manual', $instance->institution_scale_mode);
        self::assertSame(0, DB::table('autoscale_runs')->count());
    }

    // ---- the open-flags acknowledgment gate --------------------------------

    public function test_open_flags_block_a_fresh_acceptance_without_acknowledgment(): void
    {
        $this->seedInstance();
        DB::table('geodata_flags')->insert([
            ['status' => 'open', 'severity' => 'critical'],
            ['status' => 'open', 'severity' => 'warning'],
        ]);

        $service = new MapAcceptanceService($this->completeReport());
        $result = $service->accept(new MapAcceptanceOptions(mode: 'eager', gateOnVerifier: true));

        self::assertSame(MapAcceptanceResult::REQUIRES_ACKNOWLEDGMENT, $result->outcome);
        self::assertSame(1, $result->payload['open_flags']['critical']);
        self::assertSame(1, $result->payload['open_flags']['warning']);
        self::assertNull($this->currentInstance()->map_accepted_at);
    }

    // ---- already-accepted resumes a halted run -----------------------------

    public function test_reaccept_resumes_a_halted_run(): void
    {
        $this->seedInstance(['map_accepted_at' => now()->subDay()]);
        $runId = (string) Str::uuid();
        DB::table('autoscale_runs')->insert([
            'id' => $runId,
            'status' => 'halted',
            'halt_requested_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $service = new MapAcceptanceService($this->completeReport());
        $result = $service->accept(new MapAcceptanceOptions(mode: 'eager', gateOnVerifier: true));

        self::assertSame(MapAcceptanceResult::RESUMED, $result->outcome);
        self::assertTrue($result->kickPump);
        self::assertSame($runId, (string) $result->run->id);
        self::assertNull(AutoscaleRun::find($runId)->halt_requested_at, 'the halt flag is cleared');
    }

    // ---- both entry points leave identical stamp state ---------------------

    public function test_cli_and_restore_entry_points_leave_the_same_stamp_state(): void
    {
        // ENTRY POINT 1 — the maps:accept CLI command (manual mode: the
        // verifier gate is off, so no live report is needed on the fixture).
        // The command resolves MapAcceptanceService from the container.
        $this->app->instance(MapAcceptanceService::class, new MapAcceptanceService($this->completeReport()));
        $this->seedInstance();
        $cliExit = Artisan::call('maps:accept', ['--mode' => 'manual', '--acknowledge' => true]);
        self::assertSame(0, $cliExit);
        $afterCli = $this->currentInstance();
        $cliState = [
            'accepted' => $afterCli->map_accepted_at !== null,
            'step' => (int) $afterCli->setup_step_completed,
            'mode' => $afterCli->institution_scale_mode,
        ];
        $cliRuns = DB::table('autoscale_runs')->count();

        // Reset the instance to the same pre-acceptance shape.
        DB::table('instance_settings')->delete();
        DB::table('autoscale_runs')->delete();
        $this->seedInstance();

        // ENTRY POINT 2 — the backup restore path, which calls the service with
        // exactly these options (see MapDataImportService::importFromUpload).
        $service = new MapAcceptanceService($this->completeReport());
        $service->accept(new MapAcceptanceOptions(
            mode: 'manual',
            acknowledgeOpenFlags: true,
            gateOnVerifier: false,
        ));
        $afterRestore = $this->currentInstance();
        $restoreState = [
            'accepted' => $afterRestore->map_accepted_at !== null,
            'step' => (int) $afterRestore->setup_step_completed,
            'mode' => $afterRestore->institution_scale_mode,
        ];
        $restoreRuns = DB::table('autoscale_runs')->count();

        self::assertSame($cliState, $restoreState, 'the two doors leave identical stamp state');
        self::assertTrue($cliState['accepted']);
        self::assertGreaterThanOrEqual(2, $cliState['step']);
        self::assertSame('manual', $cliState['mode']);
        self::assertSame($cliRuns, $restoreRuns);
        self::assertSame(0, $restoreRuns, 'manual mode starts no run on either door');
    }

    // ---- the CLI door carries the verifier gate (the drift the item fixes) --

    public function test_maps_accept_cli_refuses_eager_when_the_world_build_is_incomplete(): void
    {
        $this->app->instance(MapAcceptanceService::class, new MapAcceptanceService($this->incompleteReport()));
        $this->seedInstance();

        $exit = Artisan::call('maps:accept', ['--mode' => 'eager']);

        self::assertSame(1, $exit, 'the CLI now gates eager acceptance on the world-build verifier');
        self::assertNull($this->currentInstance()->map_accepted_at);
        self::assertSame(0, DB::table('autoscale_runs')->count());
    }

    // ---- the seed-import restore door (maps:import / legacy federation pull) --

    /**
     * The second restore door — MapDataImportService::importSeedFromFile, reached
     * by the maps:import CLI and the legacy federation tarball pull — must leave
     * the same stamp state as the upload restore and the wizard. It calls the
     * service with these exact options after its cosmic re-point commits.
     */
    public function test_seed_restore_door_leaves_wizard_identical_stamp_state(): void
    {
        $this->seedInstance();
        // A seed import acknowledges open flags by construction and skips the
        // verifier, just like the upload restore.
        DB::table('geodata_flags')->insert(['status' => 'open', 'severity' => 'critical']);

        $service = new MapAcceptanceService($this->incompleteReport()); // never called: gate off
        $result = $service->accept(new MapAcceptanceOptions(
            mode: 'manual',
            acknowledgeOpenFlags: true,
            gateOnVerifier: false,
        ));

        self::assertSame(MapAcceptanceResult::ACCEPTED, $result->outcome);
        self::assertNull($result->run);
        self::assertFalse($result->kickPump);

        $instance = $this->currentInstance();
        self::assertNotNull($instance->map_accepted_at);
        self::assertGreaterThanOrEqual(2, (int) $instance->setup_step_completed);
        self::assertSame('manual', $instance->institution_scale_mode);
        self::assertSame(0, DB::table('autoscale_runs')->count());
    }

    /**
     * Regression guard for the reviewer finding: importSeedFromFile stamped
     * map_accepted_at with a direct DB update and no mode / step, so a
     * maps:import restore was distinguishable from a wizard acceptance. Pin that
     * the method now delegates to MapAcceptanceService and carries no stray
     * direct stamp. Read from the source of record via reflection so the guard
     * tracks the real file on any box.
     */
    public function test_seed_import_delegates_to_the_acceptance_service_not_a_direct_stamp(): void
    {
        $ref = new \ReflectionMethod(\App\Services\MapDataImportService::class, 'importSeedFromFile');
        $lines = file($ref->getFileName());
        $body = implode('', array_slice(
            $lines,
            $ref->getStartLine() - 1,
            $ref->getEndLine() - $ref->getStartLine() + 1,
        ));

        self::assertStringContainsString(
            'MapAcceptanceService',
            $body,
            'importSeedFromFile must route acceptance through the shared service',
        );
        self::assertDoesNotMatchRegularExpression(
            "/update\\(\\s*\\[\\s*'map_accepted_at'/",
            $body,
            'importSeedFromFile must not stamp map_accepted_at with a direct update',
        );
    }
}
