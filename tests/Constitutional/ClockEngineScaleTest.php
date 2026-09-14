<?php

namespace Tests\Constitutional;

use App\Jobs\ApprovalStandingsRollupJob;
use App\Jobs\Clocks\EvaluateResidencyThresholdsJob;
use App\Jobs\Elections\RankedStandingsRollupJob;
use App\Models\Election;
use App\Models\Legislature;
use App\Models\ResidencyClaim;
use App\Services\ApprovalService;
use App\Services\AuditService;
use App\Services\ClockService;
use App\Services\Elections\RankedProjectionService;
use App\Services\PublicRecordService;
use App\Services\ResidencyService;
use App\Services\SessionService;
use App\Services\SettingsResolver;
use App\Support\HostCapacity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PIN — W-0187 clock engine at planet scale.
 *
 * The clock sweep and the standings rollups must scale under the ETL rule,
 * and a newly seated chamber must carry its meeting clock. This pins:
 *
 *  1. EvaluateClocksJob dropped the fixed 500-per-sweep cap for a host-sized
 *     budget; the budget floors at 500 and the keyset chunk is host-sized.
 *  2. The approval and ranked rollups and the residency sweep are keyset
 *     chunked and resumable: runChunk pages by id, one committed chunk at a
 *     time, and a resume after a cursor rescans nothing.
 *  3. CLK-02 arms at SEATING, not only after the first adjournment, so the
 *     90-day meeting ceiling binds a chamber that has never met.
 *
 * If an edit breaks these, the edit is the violation — fix the edit.
 */
class ClockEngineScaleTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.clock_scale_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::setDefaultConnection('clock_scale_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
    }

    protected function tearDown(): void
    {
        DB::purge('clock_scale_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    // ── 1. The clock sweep budget ───────────────────────────────────────────

    public function test_evaluate_clocks_has_no_fixed_500_cap(): void
    {
        $src = (string) file_get_contents(base_path('app/Jobs/EvaluateClocksJob.php'));
        $this->assertStringNotContainsString('MAX_FIRES_PER_SWEEP', $src, 'the fixed 500 cap is gone');
        $this->assertStringContainsString('clockSweepBudget()', $src, 'the budget derives from the host');
        $this->assertStringContainsString('sweepChunk()', $src, 'the fetch is keyset-chunked');
    }

    public function test_host_sized_budget_floors_and_clamps(): void
    {
        // Defaults: the budget never regresses below 500; the chunk stays in band.
        $this->assertGreaterThanOrEqual(500, HostCapacity::clockSweepBudget());
        $chunk = HostCapacity::sweepChunk();
        $this->assertGreaterThanOrEqual(200, $chunk);
        $this->assertLessThanOrEqual(5000, $chunk);

        // The operator dials override, all derived, nothing hard-coded.
        putenv('CGA_SWEEP_CHUNK=1234');
        putenv('CGA_CLOCK_SWEEP_BUDGET=77777');
        try {
            $this->assertSame(1234, HostCapacity::sweepChunk());
            $this->assertSame(77777, HostCapacity::clockSweepBudget());
        } finally {
            putenv('CGA_SWEEP_CHUNK');
            putenv('CGA_CLOCK_SWEEP_BUDGET');
        }
    }

    // ── 2. Keyset-chunked, resumable rollups and sweeps ─────────────────────

    private function createElectionsTable(): void
    {
        DB::connection()->getSchemaBuilder()->create('elections', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('status');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
    }

    /** @return string[] the ids inserted, sorted ascending */
    private function seedElections(string $status): array
    {
        $ids = [];
        foreach (range(1, 3) as $n) {
            $id = sprintf('60000000-0000-4000-8000-%012d', $n);
            DB::table('elections')->insert(['id' => $id, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = $id;
        }
        sort($ids);

        return $ids;
    }

    public function test_approval_rollup_pages_by_keyset_and_resumes(): void
    {
        $this->createElectionsTable();
        $ids = $this->seedElections(Election::STATUS_APPROVAL_OPEN);

        $job = new class extends ApprovalStandingsRollupJob {
            /** @var string[] */
            public array $rolled = [];
            protected function rollElection(Election $election, ApprovalService $approvals): void { $this->rolled[] = (string) $election->id; }
        };
        $approvals = $this->createMock(ApprovalService::class);

        $seen = 0;
        // chunk=1 forces one election per chunk.
        $c1 = $job->runChunk($approvals, null, 1, $seen);
        $this->assertSame($ids[0], $c1, 'the cursor is the last id of the chunk');
        $this->assertSame([$ids[0]], $job->rolled, 'exactly one election rolled per chunk');

        $c2 = $job->runChunk($approvals, $c1, 1, $seen);
        $this->assertSame($ids[1], $c2, 'the resume starts after the committed cursor');

        // Drain the rest with a big chunk.
        $c3 = $job->runChunk($approvals, $c2, 100, $seen);
        $this->assertSame($ids[2], $c3);
        $this->assertNull($job->runChunk($approvals, $c3, 100, $seen), 'an empty chunk ends the pass');
        $this->assertSame($ids, $job->rolled, 'every election rolled once, none twice');
        $this->assertSame(3, $seen);
    }

    public function test_ranked_rollup_pages_by_keyset_and_resumes(): void
    {
        $this->createElectionsTable();
        $ids = $this->seedElections(Election::STATUS_RANKED_OPEN);

        $job = new class extends RankedStandingsRollupJob {
            /** @var string[] */
            public array $rolled = [];
            protected function rollElection(Election $election, RankedProjectionService $projection, AuditService $audit): void { $this->rolled[] = (string) $election->id; }
        };
        $projection = $this->createMock(RankedProjectionService::class);
        $audit = $this->createMock(AuditService::class);

        $seen = 0;
        $c1 = $job->runChunk($projection, $audit, null, 1, $seen);
        $this->assertSame($ids[0], $c1);
        $this->assertSame([$ids[0]], $job->rolled);

        $c2 = $job->runChunk($projection, $audit, $c1, 100, $seen);
        $this->assertSame($ids[2], $c2, 'a bigger chunk drains the remaining ids');
        $this->assertNull($job->runChunk($projection, $audit, $c2, 100, $seen));
        $this->assertSame($ids, $job->rolled);
    }

    public function test_residency_sweep_pages_by_keyset(): void
    {
        DB::connection()->getSchemaBuilder()->create('residency_claims', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('status');
            $t->string('jurisdiction_id')->nullable();
            $t->integer('qualifying_days')->default(0);
            $t->timestamp('deleted_at')->nullable();
        });
        $ids = [];
        foreach (range(1, 3) as $n) {
            $id = sprintf('70000000-0000-4000-8000-%012d', $n);
            DB::table('residency_claims')->insert(['id' => $id, 'status' => ResidencyClaim::STATUS_PING_MONITORING, 'qualifying_days' => 0]);
            $ids[] = $id;
        }
        sort($ids);

        // Below threshold: the sweep only walks the keyset, no transition.
        $residency = $this->createMock(ResidencyService::class);
        $residency->method('qualifyingDays')->willReturn(0);
        $residency->method('thresholdDays')->willReturn(100);
        $audit = $this->createMock(AuditService::class);

        $job = new EvaluateResidencyThresholdsJob();
        $seen = 0;
        $c1 = $job->runChunk($residency, $audit, null, 1, $seen);
        $this->assertSame($ids[0], $c1, 'one claim per chunk, ordered by id');
        $c2 = $job->runChunk($residency, $audit, $c1, 100, $seen);
        $this->assertSame($ids[2], $c2, 'the resume drains the rest after the cursor');
        $this->assertNull($job->runChunk($residency, $audit, $c2, 100, $seen), 'an empty chunk ends the pass');
        $this->assertSame(3, $seen);
    }

    // ── 3. CLK-02 arms at seating ───────────────────────────────────────────

    public function test_clk02_arms_at_seating_and_is_idempotent(): void
    {
        DB::connection()->getSchemaBuilder()->create('legislatures', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('jurisdiction_id')->nullable();
            $t->date('next_meeting_due_by')->nullable();
            $t->date('last_met_on')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        DB::connection()->getSchemaBuilder()->create('clock_timers', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('clock_id');
            $t->string('subject_type')->nullable();
            $t->string('subject_id')->nullable();
            $t->string('state')->default('armed');
            $t->timestamp('deleted_at')->nullable();
        });

        $leg = Legislature::create(['jurisdiction_id' => '80000000-0000-4000-8000-000000000001']);
        $seatedAt = CarbonImmutable::parse('2026-09-14');

        $settings = $this->createMock(SettingsResolver::class);
        $settings->method('resolveInt')->willReturn(90);

        // Fresh seating: arm() fires once with fires_at = seated + 90 days.
        $clocks = $this->createMock(ClockService::class);
        $clocks->expects($this->once())->method('arm')->with(
            'CLK-02',
            $this->anything(),
            'legislature',
            (string) $leg->id,
            $this->callback(fn ($firesAt) => $firesAt->toDateString() === '2026-12-13'),
            $this->anything(),
        )->willReturn(new \App\Models\ClockTimer(['clock_id' => 'CLK-02']));

        $session = new SessionService(
            $this->createMock(AuditService::class),
            $settings,
            $this->createMock(PublicRecordService::class),
            $clocks,
        );
        $session->armInitialMeetingClock($leg, $seatedAt);

        $this->assertSame('2026-12-13', $leg->fresh()->next_meeting_due_by->toDateString(),
            'the 90-day meeting deadline is stamped from the seating moment');

        // Idempotent: with an armed CLK-02 present, a second arm is refused.
        DB::table('clock_timers')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'clock_id' => 'CLK-02',
            'subject_type' => 'legislature', 'subject_id' => (string) $leg->id, 'state' => 'armed',
        ]);
        $clocks2 = $this->createMock(ClockService::class);
        $clocks2->expects($this->never())->method('arm');
        $session2 = new SessionService(
            $this->createMock(AuditService::class),
            $settings,
            $this->createMock(PublicRecordService::class),
            $clocks2,
        );
        $session2->armInitialMeetingClock($leg, $seatedAt);
    }

    public function test_certification_arms_the_meeting_clock_at_seating(): void
    {
        // Source pin: the seating block calls armInitialMeetingClock.
        $src = (string) file_get_contents(base_path('app/Services/CertificationService.php'));
        $this->assertStringContainsString('armInitialMeetingClock', $src,
            'certification must arm CLK-02 when the chamber is seated');
    }
}
