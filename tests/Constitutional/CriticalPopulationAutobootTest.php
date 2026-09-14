<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\AuditEntry;
use App\Models\Jurisdiction;
use App\Models\JurisdictionActivation;
use App\Services\ActivationService;
use App\Services\AuditService;
use App\Services\ElectionLifecycleService;
use App\Services\InitialDistrictMapService;
use App\Services\InstitutionStubService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PIN, W-0296 population-mode autoboot (operator ruling A, 2026-09-14).
 *
 * A CLK-06 critical-population crossing must BOOT the place. Before the fix
 * onCriticalPopulation() wrote the crossing state and stopped, so a place
 * whose resident count crossed never got its founding election or board.
 * Now the crossing calls activate() with scheduleElection true, the same
 * path the operator boot uses.
 *
 * The pin drives the real onCriticalPopulation() body against a named sqlite
 * fixture, with activate() overridden to record the call (the full boot
 * pipeline has its own coverage). It asserts:
 *   - a real crossing calls activate() once, scheduleElection true;
 *   - a repeat crossing (already past boundary_loaded) boots nothing.
 *
 * If an edit breaks these, the edit is the violation, fix the edit.
 */
class CriticalPopulationAutobootTest extends TestCase
{
    private string $original;

    /** ActivationService with activate() spied. */
    private ActivationService $svc;

    /** @var array<int,array{id:string,schedule:bool}> */
    private array $activateCalls = [];

    private const JID = '20000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.autoboot_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::setDefaultConnection('autoboot_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());

        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jurisdictions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('parent_id')->nullable();
            $t->string('slug');
            $t->string('name')->nullable();
            $t->integer('adm_level')->default(0);
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('jurisdiction_activations', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('jurisdiction_id');
            $t->string('state')->nullable();
            $t->timestamp('critical_population_at')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->string('legislature_id')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });

        DB::table('jurisdictions')->insert([
            'id' => self::JID, 'parent_id' => null, 'slug' => 'testland',
            'name' => 'Testland', 'adm_level' => 0,
        ]);

        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn(new AuditEntry());

        $calls = &$this->activateCalls;
        $this->svc = new class(
            $audit,
            $this->createMock(InstitutionStubService::class),
            $this->createMock(InitialDistrictMapService::class),
            $this->createMock(ElectionLifecycleService::class),
            $this->createMock(ConstitutionalEngine::class),
            $calls,
        ) extends ActivationService {
            /** @var array<int,array{id:string,schedule:bool}> */
            public array $spy;

            public function __construct($a, $b, $c, $d, $e, array &$calls)
            {
                parent::__construct($a, $b, $c, $d, $e);
                $this->spy = &$calls;
            }

            public function activate(Jurisdiction $jurisdiction, bool $scheduleElection = true): JurisdictionActivation
            {
                $this->spy[] = ['id' => (string) $jurisdiction->id, 'schedule' => $scheduleElection];

                return new JurisdictionActivation(['jurisdiction_id' => $jurisdiction->id, 'state' => JurisdictionActivation::STATE_SELF_GOVERNING]);
            }
        };
    }

    protected function tearDown(): void
    {
        DB::purge('autoboot_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public function test_a_first_crossing_writes_the_state_and_boots_by_calling_activate(): void
    {
        // No activation row yet: a bare boundary crossing the threshold.
        $this->svc->onCriticalPopulation(self::JID, 150, 100);

        $row = DB::table('jurisdiction_activations')->where('jurisdiction_id', self::JID)->first();
        $this->assertNotNull($row, 'the crossing is written');
        $this->assertSame(JurisdictionActivation::STATE_CRITICAL_POPULATION, $row->state);

        $this->assertCount(1, $this->activateCalls, 'the crossing boots exactly once');
        $this->assertSame(self::JID, $this->activateCalls[0]['id']);
        $this->assertTrue($this->activateCalls[0]['schedule'], 'the founding election is scheduled from the crossing');
    }

    public function test_a_boundary_loaded_row_crosses_and_boots(): void
    {
        DB::table('jurisdiction_activations')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'jurisdiction_id' => self::JID,
            'state' => JurisdictionActivation::STATE_BOUNDARY_LOADED,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->svc->onCriticalPopulation(self::JID, 150, 100);

        $this->assertCount(1, $this->activateCalls, 'a boundary_loaded row crosses and boots');
        $this->assertSame(JurisdictionActivation::STATE_CRITICAL_POPULATION,
            DB::table('jurisdiction_activations')->where('jurisdiction_id', self::JID)->value('state'));
    }

    public function test_a_repeat_crossing_boots_nothing(): void
    {
        // Already past boundary_loaded: the idempotency guard returns the row
        // untouched and never boots twice.
        DB::table('jurisdiction_activations')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'jurisdiction_id' => self::JID,
            'state' => JurisdictionActivation::STATE_CRITICAL_POPULATION,
            'critical_population_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->svc->onCriticalPopulation(self::JID, 200, 100);

        $this->assertCount(0, $this->activateCalls, 'a place already past the crossing is never booted again');
    }
}
