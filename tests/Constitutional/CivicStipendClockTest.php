<?php

namespace Tests\Constitutional;

use App\Jobs\Clocks\RunCivicStipendJob;
use App\Models\ClockTimer;
use App\Services\ClockService;
use App\Services\Demo\SimEconomyService;
use App\Services\Economy\StipendClockService;
use Carbon\CarbonImmutable;
use Database\Seeders\ClockRegistrySeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PIN, W-0201 civic stipend clock (CLK-22).
 *
 * The stipend runs inside the simulation. Outside a sim nothing fired it, so
 * the treasury page showed a next-run date with no clock behind it. CLK-22 is
 * the standalone clock: armed on treasury mint, fired by the clock engine
 * (RunCivicStipendJob), re-armed each period, and the treasury next-run reads
 * the real timer.
 *
 * A named sqlite fixture drives the arming and the chunked roster; the
 * registry route is a source-level pin. If an edit breaks these, the edit is
 * the violation, fix the edit.
 */
class CivicStipendClockTest extends TestCase
{
    private string $original;

    private const ROOT = '30000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.stipend_clock_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::setDefaultConnection('stipend_clock_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());

        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jurisdictions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('parent_id')->nullable();
            $t->string('slug');
            $t->integer('adm_level')->default(0);
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('clocks', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->text('default_value')->nullable();
            $t->timestamps();
        });
        $schema->create('clock_timers', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('clock_id');
            $t->string('jurisdiction_id')->nullable();
            $t->string('subject_type')->nullable();
            $t->string('subject_id')->nullable();
            $t->timestamp('armed_at')->nullable();
            $t->timestamp('fires_at')->nullable();
            $t->string('state')->default('armed');
            $t->text('payload')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('residency_confirmations', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('user_id');
            $t->string('jurisdiction_id')->nullable();
            $t->boolean('is_active');
        });
        $schema->create('ubi_disbursements', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('jurisdiction_id');
            $t->string('currency_id')->nullable();
            $t->timestamp('ran_at');
        });
        $schema->create('currencies', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('jurisdiction_id');
            $t->timestamp('deleted_at')->nullable();
        });

        DB::table('jurisdictions')->insert(['id' => self::ROOT, 'parent_id' => null, 'slug' => 'earth', 'adm_level' => 0]);
        DB::table('clocks')->insert(['id' => 'CLK-22', 'default_value' => json_encode(['value' => 30, 'unit' => 'days', 'setting_key' => 'stipend_period_days']), 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        DB::purge('stipend_clock_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    // ── Registry route (source-level pin) ───────────────────────────────────

    public function test_clk22_routes_to_the_stipend_job(): void
    {
        $this->assertSame(RunCivicStipendJob::class, ClockService::HANDLERS['CLK-22'] ?? null,
            'CLK-22 must fire the standalone civic stipend job');
    }

    public function test_clk22_is_in_the_clock_registry(): void
    {
        $rows = collect(ClockRegistrySeeder::registry())->keyBy('id');
        $this->assertTrue($rows->has('CLK-22'), 'the seeder registry carries CLK-22');
        $this->assertSame('recurring', $rows['CLK-22']['type']);
        $this->assertSame('stipend_period_days', $rows['CLK-22']['default_value']['setting_key']);

        // And a real-dated additive migration seeds it for boxes past the flatten.
        $this->assertFileExists(base_path('database/migrations/2026_09_14_120000_seed_clk22_stipend_clock.php'));
    }

    // ── Arming behaviour ────────────────────────────────────────────────────

    public function test_arm_for_root_arms_when_none_exists(): void
    {
        $clocks = $this->createMock(ClockService::class);
        $clocks->method('resolvedInt')->willReturn(30);
        $armed = new ClockTimer(['clock_id' => 'CLK-22']);
        $clocks->expects($this->once())->method('arm')->willReturn($armed);

        $svc = new StipendClockService($clocks);
        $result = $svc->armForRoot(CarbonImmutable::parse('2026-09-14'));

        $this->assertSame($armed, $result, 'a world with no armed CLK-22 arms one');
    }

    public function test_arm_for_root_is_idempotent(): void
    {
        // A CLK-22 already armed for the root.
        DB::table('clock_timers')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'clock_id' => 'CLK-22',
            'subject_type' => 'jurisdiction', 'subject_id' => self::ROOT,
            'state' => 'armed', 'armed_at' => now(), 'fires_at' => now()->addDays(30),
        ]);

        $clocks = $this->createMock(ClockService::class);
        $clocks->expects($this->never())->method('arm');

        $svc = new StipendClockService($clocks);
        $this->assertNull($svc->armForRoot(), 'an already-armed world arms nothing');
    }

    public function test_next_run_at_reads_the_real_timer(): void
    {
        $firesAt = now()->addDays(12)->startOfSecond();
        DB::table('clock_timers')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'clock_id' => 'CLK-22',
            'subject_type' => 'jurisdiction', 'subject_id' => self::ROOT,
            'state' => 'armed', 'armed_at' => now(), 'fires_at' => $firesAt,
        ]);

        $svc = new StipendClockService($this->createMock(ClockService::class));
        $next = $svc->nextRunAt(self::ROOT);

        $this->assertNotNull($next);
        $this->assertSame($firesAt->toDateString(), $next->toDateString(), 'the next run is the armed timer, not a guess');
    }

    // ── The chunked, resumable, idempotent pass ─────────────────────────────

    public function test_the_roster_pages_active_jurisdictions_by_keyset(): void
    {
        DB::table('residency_confirmations')->insert([
            ['id' => 'a', 'user_id' => 'u1', 'jurisdiction_id' => '40000000-0000-4000-8000-000000000001', 'is_active' => true],
            ['id' => 'b', 'user_id' => 'u2', 'jurisdiction_id' => '40000000-0000-4000-8000-000000000001', 'is_active' => true],
            ['id' => 'c', 'user_id' => 'u3', 'jurisdiction_id' => '40000000-0000-4000-8000-000000000002', 'is_active' => true],
            ['id' => 'd', 'user_id' => 'u4', 'jurisdiction_id' => '40000000-0000-4000-8000-000000000003', 'is_active' => true],
            ['id' => 'e', 'user_id' => 'u5', 'jurisdiction_id' => '40000000-0000-4000-8000-000000000009', 'is_active' => false],
        ]);

        $job = new class extends RunCivicStipendJob {
            public function roster(?string $after, int $limit): array { return $this->jurisdictionRoster($after, $limit); }
        };

        // limit 1 forces multiple keyset chunks; the inactive place never appears.
        $first = $job->roster(null, 1);
        $this->assertSame(['40000000-0000-4000-8000-000000000001'], $first, 'distinct, active, one per chunk');

        $second = $job->roster($first[0], 1);
        $this->assertSame(['40000000-0000-4000-8000-000000000002'], $second, 'the keyset resumes after the cursor');

        $third = $job->roster($second[0], 10);
        $this->assertSame(['40000000-0000-4000-8000-000000000003'], $third, 'the last active place, the inactive one filtered out');

        $this->assertSame([], $job->roster('40000000-0000-4000-8000-000000000003', 10), 'the pass ends when the keyset is drained');
    }

    public function test_a_jurisdiction_paid_this_period_is_skipped(): void
    {
        DB::table('ubi_disbursements')->insert(['id' => 'x', 'jurisdiction_id' => '40000000-0000-4000-8000-000000000009', 'ran_at' => now()->subDays(2)]);

        // A fake stipend service must NOT be called when already paid.
        $called = 0;
        app()->instance(SimEconomyService::class, new class($called) extends SimEconomyService {
            public int $n;
            public function __construct(int &$called) { $this->n = &$called; }
            public function runStipendFor(string $jurisdictionId, ?\Closure $beat = null): ?array { $this->n++; return ['ok' => true]; }
        });

        $job = new class extends RunCivicStipendJob {
            public function pay(string $jid, int $periodDays): bool { return $this->payJurisdiction($jid, $periodDays); }
        };

        $this->assertFalse($job->pay('40000000-0000-4000-8000-000000000009', 30), 'a place paid inside the period is skipped');
        $this->assertSame(0, app(SimEconomyService::class)->n, 'the stipend service is never called for a skip');
    }

    public function test_a_jurisdiction_not_yet_paid_runs_the_stipend(): void
    {
        $called = 0;
        app()->instance(SimEconomyService::class, new class($called) extends SimEconomyService {
            public int $n;
            public function __construct(int &$called) { $this->n = &$called; }
            public function runStipendFor(string $jurisdictionId, ?\Closure $beat = null): ?array { $this->n++; return ['ok' => true]; }
        });

        $job = new class extends RunCivicStipendJob {
            public function pay(string $jid, int $periodDays): bool { return $this->payJurisdiction($jid, $periodDays); }
        };

        $this->assertTrue($job->pay('40000000-0000-4000-8000-00000000000a', 30), 'an unpaid place runs the stipend');
        $this->assertSame(1, app(SimEconomyService::class)->n);
    }

    public function test_the_handler_re_arms_the_next_period(): void
    {
        // Source pin: the fired handler rolls the next period at the end.
        $src = (string) file_get_contents(base_path('app/Jobs/Clocks/RunCivicStipendJob.php'));
        $this->assertStringContainsString('$clock->reArm()', $src, 'the stipend pass must re-arm CLK-22 for the next period');
    }

    public function test_treasury_next_run_reads_the_timer_not_the_estimate(): void
    {
        // The gap the source pin missed: the treasury surface (economicClock)
        // fed next_run from last_run + period and showed null before the first
        // disbursement, though CLK-22 was armed. Drive economicClock with an
        // armed timer and NO disbursement; next_run must be the armed instant.
        $firesAt = now()->addDays(7)->startOfSecond();
        DB::table('clock_timers')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'clock_id' => 'CLK-22',
            'subject_type' => 'jurisdiction', 'subject_id' => self::ROOT,
            'state' => 'armed', 'armed_at' => now(), 'fires_at' => $firesAt,
        ]);

        $settings = $this->createMock(\App\Services\SettingsResolver::class);
        $settings->method('resolve')->willReturnCallback(
            fn (string $j, string $k) => $k === 'stipend_period_days' ? 30 : 'monthly'
        );

        $controller = new \App\Http\Controllers\Economy\EconomyController(
            $this->createMock(\App\Services\Economy\LedgerService::class),
            $this->createMock(\App\Services\Economy\IssuanceService::class),
            $this->createMock(\App\Services\Economy\AccountService::class),
            $settings,
            $this->createMock(\App\Services\Economy\CurrencyTelemetryService::class),
        );

        $m = new \ReflectionMethod($controller, 'economicClock');
        $m->setAccessible(true);
        $clock = $m->invoke($controller);

        $this->assertNull($clock['last_run'], 'no disbursement has run yet');
        $this->assertNotNull($clock['next_run'], 'an armed clock must show a next run even before the first disbursement');
        $this->assertSame(
            $firesAt->toIso8601String(),
            $clock['next_run'],
            'the treasury next run is the armed CLK-22 timer, not the last-run estimate'
        );

        // Source pin retained: the economy page reads the armed timer.
        $src = (string) file_get_contents(base_path('app/Http/Controllers/Economy/EconomyController.php'));
        $this->assertStringContainsString('StipendClockService', $src);
        $this->assertStringContainsString('nextRunAt', $src, 'next_run_estimate must read the real CLK-22 timer');
    }
}
