<?php

namespace Tests\Feature;

use App\Jobs\PrewarmRasterTilesJob;
use App\Support\InstanceClass;
use App\Support\RunsInFlight;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Operator rulings 2026-09-17 (from the WoS beta boot report):
 *   serving-boot-prewarm = A   the prewarm commands skip while any run is active
 *   beta-instance-class = A    a founded box flips to scale_demo as an operator act
 *
 * Runs on the phpunit sqlite :memory: fixture. Only the tables each test reads
 * are built in setUp (no RefreshDatabase: forbidden). The audit log is
 * Postgres-only, so InstanceClass::change reports audited=false here.
 */
class OperatorRulings20260917Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        InstanceClass::flush();

        Schema::create('geodata_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('status', 16);
            $t->timestamps();
        });
        Schema::create('instance_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('instance_name')->nullable();
            $t->string('map_mode')->nullable();
            $t->string('time_mode')->nullable();
            $t->integer('setup_step_completed')->default(0);
            $t->string('instance_class', 16)->default('production');
            $t->timestamps();
            $t->softDeletes();
        });
        DB::table('instance_settings')->insert([
            'id' => (string) Str::uuid(), 'instance_name' => 'Fixture', 'instance_class' => 'production',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        InstanceClass::flush();
        parent::tearDown();
    }

    public function test_runs_in_flight_is_idle_with_no_active_run_and_names_an_active_one(): void
    {
        $this->assertNull(RunsInFlight::any());

        DB::table('geodata_runs')->insert(['id' => (string) Str::uuid(), 'status' => 'done', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertNull(RunsInFlight::any(), 'a finished run is not in flight');

        DB::table('geodata_runs')->insert(['id' => (string) Str::uuid(), 'status' => 'halted', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('geodata', RunsInFlight::any(), 'a halted run counts: its pool may resume at any tick');
    }

    public function test_the_raster_prewarm_dispatches_when_idle_and_skips_with_unless_busy_when_a_run_is_active(): void
    {
        Artisan::call('rasters:prewarm', ['--max-zoom' => 6, '--queue' => true, '--unless-busy' => true]);
        Queue::assertPushed(PrewarmRasterTilesJob::class, 1);

        DB::table('geodata_runs')->insert(['id' => (string) Str::uuid(), 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);
        Artisan::call('rasters:prewarm', ['--max-zoom' => 6, '--queue' => true, '--unless-busy' => true]);
        $this->assertStringContainsString('skipped', Artisan::output());
        Queue::assertPushed(PrewarmRasterTilesJob::class, 1, 'no second dispatch while a run is active');

        // Without the flag the other profiles keep their behaviour.
        Artisan::call('rasters:prewarm', ['--max-zoom' => 6, '--queue' => true]);
        Queue::assertPushed(PrewarmRasterTilesJob::class, 2);
    }

    public function test_instance_class_change_flips_the_singleton_and_reports_the_audit_state(): void
    {
        $this->assertSame('production', InstanceClass::current());

        $result = InstanceClass::change('scale_demo', null, 'conference demo');

        $this->assertSame(['from' => 'production', 'to' => 'scale_demo', 'audited' => false], $result);
        $this->assertSame('scale_demo', DB::table('instance_settings')->value('instance_class'));
        $this->assertSame('scale_demo', InstanceClass::current(), 'the per-request cache is flushed');
        $this->assertTrue(InstanceClass::isScaleDemo());

        $back = InstanceClass::change('production');
        $this->assertSame('production', $back['to']);
        $this->assertSame('production', InstanceClass::current());
    }

    public function test_an_unknown_class_normalises_to_production_and_a_same_class_change_is_a_no_op(): void
    {
        $result = InstanceClass::change('whatever');
        $this->assertSame('production', $result['to']);
        $this->assertSame('production', DB::table('instance_settings')->value('instance_class'));
    }

    public function test_the_console_command_shows_flips_and_refuses_a_bad_class(): void
    {
        Artisan::call('instance:class');
        $this->assertStringContainsString('instance_class = production', Artisan::output());

        $this->assertSame(0, Artisan::call('instance:class', ['class' => 'scale_demo', '--yes' => true, '--reason' => 'test']));
        $this->assertSame('scale_demo', DB::table('instance_settings')->value('instance_class'));

        $this->assertSame(2, Artisan::call('instance:class', ['class' => 'sandbox', '--yes' => true]));
    }
}
