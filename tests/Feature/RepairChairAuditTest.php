<?php

namespace Tests\Feature;

use App\Models\{Board, BoardSeat, Organization, SimRun};
use App\Services\{AuditService, PublicRecordService};
use App\Services\Demo\{RepairChairAudit, SimChairService, SimRepairService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\DisposableRepairWorld;
use Tests\TestCase;

class RepairChairAuditTest extends TestCase
{
    use DisposableRepairWorld;
    protected function setUp(): void { parent::setUp(); $this->openRepairWorld(); config(['cache.default' => 'array', 'queue.default' => 'sync']); }
    protected function tearDown(): void { RepairChairAudit::end(); $this->closeRepairWorld(); parent::tearDown(); }
    private function id(): string { return (string) Str::uuid(); }
    private function world(): array
    {
        $scope = $this->id();
        DB::table('jurisdictions')->insert(['id' => $scope, 'name' => 'Chair fixture', 'slug' => $scope, 'population' => 1000, 'adm_level' => 6]);
        $source = SimRun::create(['status' => 'done', 'phase' => 'done', 'options' => [], 'phase_timings' => []]);
        $run = SimRun::create(['status' => 'running', 'phase' => 'repairing', 'options' => ['repair_source_run' => $source->id, 'repair_version' => 1], 'phase_timings' => []]);
        return [$scope, $run];
    }
    private function board(string $scope): Board
    {
        $org = Organization::create(['name' => 'Board fixture', 'slug' => $this->id(), 'jurisdiction_id' => $scope, 'type' => 'business', 'status' => 'active']);
        $board = Board::create(['boardable_type' => 'organizations', 'boardable_id' => $org->id, 'status' => 'active', 'owner_seats' => 3, 'worker_seats' => 0]);
        for ($i = 1; $i <= 3; $i++) {
            $person = $this->id(); DB::table('users')->insert(['id' => $person, 'name' => 'Fixture', 'email' => $person.'@example.test', 'password' => 'unused', 'terms_accepted_at' => now()]);
            BoardSeat::create(['board_id' => $board->id, 'seat_class' => 'owner_elected', 'seat_no' => $i, 'holder_user_id' => $person, 'status' => 'seated']);
        }
        return $board;
    }
    private function action(SimRun $run, string $scope, Board $board, ?\Closure $perform = null): array
    {
        $service = app(SimRepairService::class);
        return (new \ReflectionMethod($service, 'action'))->invoke($service, $run, $scope, 'chair', $board->id,
            $perform ?? fn () => app(SimChairService::class)->complete($board->id));
    }
    private function checkRecords(Board $board): void
    {
        $vote = DB::table('chamber_votes')->where('body_id', $board->id)->first();
        self::assertSame('adopted', $vote->outcome);
        $casts = DB::table('vote_casts')->where('vote_id', $vote->id)->get(); self::assertCount(3, $casts);
        foreach ($casts as $cast) {
            $record = DB::table('public_records')->where('id', $cast->public_record_id)->first();
            self::assertNotNull($record); self::assertGreaterThan(0, $record->audit_seq);
            $audit = DB::table('audit_log')->where('seq', $record->audit_seq)->first();
            self::assertSame($record->id, json_decode($audit->payload, true)['record_id']);
            self::assertSame('published', $audit->event);
        }
        self::assertTrue(app(AuditService::class)->verifyChain());
    }

    public function test_real_chair_events_and_ballot_records_are_sealed_once_and_redelivery_is_noop(): void
    {
        [$scope, $run] = $this->world(); $board = $this->board($scope);
        self::assertSame('applied', $this->action($run, $scope, $board)['status']);
        $this->checkRecords($board);
        $audit = DB::table('audit_log')->count(); $records = DB::table('public_records')->count();
        self::assertTrue($this->action($run, $scope, $board)['reused']);
        self::assertSame($audit, DB::table('audit_log')->count()); self::assertSame($records, DB::table('public_records')->count());
        self::assertFalse(RepairChairAudit::active());
    }

    public function test_savepoint_rollback_discards_staged_records_and_events_and_preserves_training_visibility(): void
    {
        [$scope, $run] = $this->world(); $board = $this->board($scope);
        $out = $this->action($run, $scope, $board, function () use ($scope, $board) {
            try { DB::transaction(function () use ($scope) {
                app(PublicRecordService::class)->publish('other', 'rolled back fixture', attrs: ['jurisdiction_id' => $scope]);
                app(AuditService::class)->append('fixture', 'rollback.event', []);
                throw new \RuntimeException('savepoint');
            }); } catch (\RuntimeException $e) { self::assertSame('savepoint', $e->getMessage()); }
            app(AuditService::class)->append('education', 'education.training_completed', ['track_key' => 'fixture'], 'F-EDU-001');
            return app(SimChairService::class)->complete($board->id);
        });
        self::assertSame('applied', $out['status']); $this->checkRecords($board);
        self::assertSame(0, DB::table('audit_log')->where('event', 'rollback.event')->count());
        self::assertSame(0, DB::table('public_records')->where('title', 'rolled back fixture')->count());
        self::assertSame(1, DB::table('audit_log')->where('event', 'education.training_completed')->where('ref', 'F-EDU-001')->count());
    }

    public function test_failures_before_and_after_flush_leave_no_domain_or_audit_success(): void
    {
        [$scope, $run] = $this->world();
        foreach ([false, true] as $afterFlush) {
            $board = $this->board($scope); $audit = DB::table('audit_log')->count(); $records = DB::table('public_records')->count();
            $result = $this->action($run, $scope, $board, function () use ($board, $afterFlush) {
                app(SimChairService::class)->complete($board->id);
                if ($afterFlush) { RepairChairAudit::flush(); }
                throw new \RuntimeException('injected action failure');
            });
            self::assertSame('blocked', $result['status']); self::assertNull($board->refresh()->chair_seat_id);
            self::assertSame(0, DB::table('chamber_votes')->where('body_id', $board->id)->count());
            self::assertSame($audit, DB::table('audit_log')->count()); self::assertSame($records, DB::table('public_records')->count());
            self::assertFalse(RepairChairAudit::active());
        }
        $good = $this->board($scope); self::assertSame('applied', $this->action($run, $scope, $good)['status']); $this->checkRecords($good);
    }

    public function test_another_real_repair_commits_while_first_has_finished_ballots_but_not_audit_flush(): void
    {
        [$scope, $run] = $this->world(); $first = $this->board($scope); $second = $this->board($scope);
        DB::commit(); // Fixture only: child process must see committed boards.
        $database = DB::connection()->getDatabaseName();
        $out = $this->action($run, $scope, $first, function () use ($first, $second, $scope, $run, $database) {
            $result = app(SimChairService::class)->complete($first->id);
            // The old early global lock would block this child until timeout.
            $child = new Process(['php', base_path('tests/Support/repair_chair_process.php'), $database, $run->id, $scope, $second->id],
                base_path(), ['RUN_SIM_INDEX_PG_TESTS' => '1']);
            $child->setTimeout(10); $child->run();
            self::assertTrue($child->isSuccessful(), $child->getOutput().$child->getErrorOutput());
            self::assertSame('applied', json_decode($child->getOutput(), true)['status']);
            return $result;
        });
        self::assertSame('applied', $out['status']); $this->checkRecords($first); $this->checkRecords($second);
        $run->forceFill(['status' => 'done', 'phase' => 'done'])->save();
        DB::beginTransaction();
    }
}
