<?php

namespace Tests\Feature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\EngineResult;
use App\Models\AuditEntry;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\Department;
use App\Models\ExecutiveMember;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\ChamberVoteService;
use App\Services\Demo\Stages\GovernanceStage;
use App\Services\Executive\BoardGovernorService;
use App\Support\SimTimer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Stage orchestration/query regressions; real consent is covered by SimDepartmentGovernorStageTest. */
class Phase7PerformanceTest extends TestCase
{
    private ?string $fixture = null;
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') {
            $this->markTestSkipped('Opt in to the disposable Phase 7 database.');
        }
        $this->original = DB::getDefaultConnection();
        config(['database.connections.phase7_admin' => array_replace(config('database.connections.pgsql'),
            ['database' => 'postgres', 'name' => 'phase7_admin', 'url' => null])]);
        $admin = DB::connection('phase7_admin');
        $this->assertSame('postgres', $admin->selectOne('SELECT current_database() AS name')->name);
        $this->fixture = 'phase7_test_'.bin2hex(random_bytes(8));
        $admin->statement('CREATE DATABASE '.$this->fixture.' TEMPLATE template0');
        config(['database.connections.phase7_test' => array_replace($admin->getConfig(),
            ['database' => $this->fixture, 'name' => 'phase7_test', 'url' => null])]);
        DB::setDefaultConnection('phase7_test');
        $this->assertSame($this->fixture, DB::selectOne('SELECT current_database() AS name')->name);
        $models = [new Department, new ExecutiveMember, new BoardSeat, new ChamberVote,
            new Legislature, new LegislatureMember, new User];
        foreach ($models as $model) {
            $this->assertSame('phase7_test', $model->getConnection()->getName());
            $this->assertSame($this->fixture, $model->getConnection()->selectOne('SELECT current_database() AS name')->name);
        }
        DB::statement("SET lock_timeout = '2s'");
        DB::statement("SET statement_timeout = '15s'");
        foreach ($models as $model) {
            DB::connection()->getSchemaBuilder()->create($model->getTable(), function (Blueprint $table) use ($model) {
                foreach (array_unique([...$model->getFillable(), 'id', 'created_at', 'updated_at', 'deleted_at']) as $column) {
                    $column === 'id' ? $table->uuid('id')->primary() : $table->text($column)->nullable();
                }
            });
        }
        DB::statement('CREATE TABLE residency_confirmations (user_id uuid, jurisdiction_id uuid, is_active boolean)');
        DB::statement('CREATE TABLE committees (id uuid PRIMARY KEY, legislature_id uuid, name text, deleted_at timestamptz)');
        DB::table('residency_confirmations')->insert(['user_id' => $this->id(100), 'jurisdiction_id' => $this->id(1), 'is_active' => true]);
        $this->resetTimers();
    }

    protected function tearDown(): void
    {
        $this->resetTimers();
        if ($this->fixture !== null) {
            while (DB::transactionLevel() > 0) { DB::rollBack(); }
            DB::setDefaultConnection($this->original);
            DB::purge('phase7_test');
            if (! preg_match('/^phase7_test_[a-f0-9]{16}$/D', $this->fixture)) {
                throw new \LogicException('Unexpected fixture database.');
            }
            DB::connection('phase7_admin')->statement('DROP DATABASE '.$this->fixture.' WITH (FORCE)');
            DB::purge('phase7_admin');
        }
        parent::tearDown();
    }

    private function resetTimers(): void
    {
        foreach (['us', 'n', 'max', 'open'] as $property) {
            (new \ReflectionProperty(SimTimer::class, $property))->setValue(null, []);
        }
    }

    private function id(int $value): string
    {
        return sprintf('70000000-0000-4000-8000-%012d', $value);
    }

    private function legislature(int $seats = 20): Legislature
    {
        return (new Legislature)->forceFill(['id' => $this->id(2), 'jurisdiction_id' => $this->id(1),
            'total_seats' => $seats, 'type_b_seats' => 0]);
    }

    private function department(int $number, int $seatCount = 1): Department
    {
        $department = Department::create(['id' => $this->id(200 + $number), 'jurisdiction_id' => $this->id(1),
            'executive_id' => $this->id(3), 'board_id' => $this->id(300 + $number),
            'name' => 'Department '.$number, 'status' => Department::STATUS_CHARTERED]);
        for ($seat = 1; $seat <= $seatCount; $seat++) {
            BoardSeat::create(['id' => $this->id(1000 + 10 * $number + $seat), 'board_id' => $department->board_id,
                'seat_class' => BoardSeat::CLASS_GOVERNOR, 'status' => BoardSeat::STATUS_VACANT, 'seat_no' => $seat]);
        }
        return $department;
    }

    private function principal(int $id = 4): ExecutiveMember
    {
        return ExecutiveMember::create(['id' => $this->id($id), 'executive_id' => $this->id(3),
            'user_id' => $this->id(100 + $id), 'role' => ExecutiveMember::ROLE_PRINCIPAL,
            'status' => ExecutiveMember::STATUS_SEATED]);
    }

    public function test_governor_loop_removes_two_queries_per_department_and_selects_a_fresh_principal(): void
    {
        $first = $this->principal();
        $second = $this->principal(5);
        $this->department(1, 2);
        $this->department(2);
        $serving = collect([(new LegislatureMember)->forceFill(['id' => $this->id(50)])]);
        $nominators = [];
        $governors = $this->createMock(BoardGovernorService::class);
        $governors->expects($this->exactly(3))->method('nominate')->willReturnCallback(
            function ($department, $principal, $nominee) use (&$nominators) {
                $this->assertSame($this->id(100), $nominee);
                $nominators[] = $principal->id;
                $seat = BoardSeat::where('board_id', $department->board_id)->where('status', 'vacant')->orderBy('seat_no')->firstOrFail();
                $seat->update(['status' => BoardSeat::STATUS_NOMINATED]);
                $vote = ChamberVote::create(['status' => 'open', 'votable_id' => $seat->id]);
                return ['seat_id' => $seat->id, 'consent_vote_id' => $vote->id];
            });
        $votes = $this->createMock(ChamberVoteService::class);
        $votes->expects($this->exactly(3))->method('castManyYes')->willReturnCallback(
            function ($vote, $members) use ($serving, $first) {
                $this->assertSame($serving, $members);
                BoardSeat::whereKey($vote->votable_id)->update(['status' => BoardSeat::STATUS_SEATED]);
                // The first department's last consent changes the next eligible
                // principal. A per-call cache would incorrectly reuse member 4.
                if ($vote->votable_id === $this->id(1012)) {
                    $first->update(['status' => ExecutiveMember::STATUS_LEFT]);
                }
                return 1;
            });
        $this->app->instance(BoardGovernorService::class, $governors);
        $this->app->instance(ChamberVoteService::class, $votes);
        DB::enableQueryLog(); DB::flushQueryLog();
        $out = GovernanceStage::seatDepartmentGovernors($this->legislature(), $serving);
        $queries = collect(DB::getQueryLog())->pluck('query'); DB::disableQueryLog();
        $this->assertSame(['departments' => 2, 'nominated' => 3, 'seated' => 3, 'skipped' => null], $out);
        $this->assertSame([$first->id, $first->id, $second->id], $nominators);
        $this->assertCount(2, $queries->filter(fn ($sql) => str_starts_with($sql, 'select * from "executive_members"') && str_contains($sql, 'exists (')));
        $this->assertCount(3, $queries->filter(fn ($sql) => str_starts_with($sql, 'select exists(')), 'Only one post-consent vacancy read per successful seat; previously seven.');
        $timers = (new \ReflectionProperty(SimTimer::class, 'n'))->getValue();
        $this->assertSame(3, $timers['gov.governor_nominate']);
        $this->assertSame(3, $timers['gov.governor_consent']);
        $this->assertSame(3, BoardSeat::where('status', BoardSeat::STATUS_SEATED)->count());
    }

    public function test_ineligible_and_deleted_seats_do_not_open_nominations(): void
    {
        $this->principal();
        $workerBoard = $this->department(1);
        BoardSeat::where('board_id', $workerBoard->board_id)->update(['seat_class' => BoardSeat::CLASS_WORKER_ELECTED]);
        $deletedBoard = $this->department(2);
        BoardSeat::where('board_id', $deletedBoard->board_id)->delete();
        $filledBoard = $this->department(3);
        BoardSeat::where('board_id', $filledBoard->board_id)->update(['status' => BoardSeat::STATUS_SEATED]);
        $governors = $this->createMock(BoardGovernorService::class);
        $governors->expects($this->never())->method('nominate');
        $this->app->instance(BoardGovernorService::class, $governors);
        $this->app->instance(ChamberVoteService::class, $this->createMock(ChamberVoteService::class));
        $this->assertSame(['departments' => 0, 'nominated' => 0, 'seated' => 0, 'skipped' => null],
            GovernanceStage::seatDepartmentGovernors($this->legislature(), collect()));
    }

    public function test_a_refused_nomination_stops_only_that_board_and_records_its_timing(): void
    {
        $this->principal();
        $this->department(1, 2);
        $this->department(2);
        $governors = $this->createMock(BoardGovernorService::class);
        $governors->expects($this->exactly(2))->method('nominate')->willThrowException(new \RuntimeException('Guard refused'));
        $votes = $this->createMock(ChamberVoteService::class);
        $votes->expects($this->never())->method('castManyYes');
        $this->app->instance(BoardGovernorService::class, $governors);
        $this->app->instance(ChamberVoteService::class, $votes);
        $out = GovernanceStage::seatDepartmentGovernors($this->legislature(), collect());
        $this->assertSame(['departments' => 2, 'nominated' => 0, 'seated' => 0, 'skipped' => null], $out);
        $this->assertSame(3, BoardSeat::where('status', BoardSeat::STATUS_VACANT)->count());
        $this->assertSame(2, (new \ReflectionProperty(SimTimer::class, 'n'))->getValue()['gov.governor_nominate']);
    }

    public function test_committee_inventory_is_read_once_and_still_files_every_new_act_and_vote(): void
    {
        $legislature = $this->legislature(); // Target four; existing two, even with repeated names.
        foreach ([10 => null, 11 => null, 12 => now()] as $number => $deleted) {
            DB::table('committees')->insert(['id' => $this->id($number), 'legislature_id' => $legislature->id,
                'name' => $number === 12 ? 'Budget' : 'Rules', 'deleted_at' => $deleted]);
        }
        $actor = (new User)->forceFill(['id' => $this->id(40)]);
        $serving = collect([(new LegislatureMember)->forceFill(['id' => $this->id(50)])]);
        $names = [];
        $engine = $this->createMock(ConstitutionalEngine::class);
        $engine->expects($this->exactly(2))->method('file')->willReturnCallback(function ($form, $proposer, $payload) use ($actor, &$names) {
            $this->assertSame('F-LEG-009', $form);
            $this->assertSame($actor, $proposer);
            $this->assertSame(1, $payload['seats']);
            $names[] = $payload['name'];
            $vote = ChamberVote::create(['status' => 'open']);
            return new EngineResult($form, new AuditEntry, ['vote_id' => $vote->id]);
        });
        $votes = $this->createMock(ChamberVoteService::class);
        $votes->expects($this->exactly(2))->method('castManyYes')->with($this->isInstanceOf(ChamberVote::class), $serving)->willReturn(1);
        $this->app->instance(ConstitutionalEngine::class, $engine);
        $this->app->instance(ChamberVoteService::class, $votes);
        DB::enableQueryLog(); DB::flushQueryLog();
        $out = (new \ReflectionMethod(GovernanceStage::class, 'growCommittees'))->invoke(null, $legislature, $serving, $actor);
        $queries = collect(DB::getQueryLog())->pluck('query'); DB::disableQueryLog();
        $this->assertSame(['created' => 2, 'target' => 4, 'existing' => 2, 'skipped' => null], $out);
        $this->assertSame(['Budget', 'Oversight'], $names);
        $this->assertCount(1, $queries->filter(fn ($sql) => str_contains($sql, 'from "committees"')));
    }

    public function test_at_target_committee_inventory_never_files_or_removes_a_governed_choice(): void
    {
        $legislature = $this->legislature(5);
        foreach ([10, 11] as $number) {
            DB::table('committees')->insert(['id' => $this->id($number), 'legislature_id' => $legislature->id, 'name' => 'Chosen '.$number]);
        }
        $engine = $this->createMock(ConstitutionalEngine::class);
        $engine->expects($this->never())->method('file');
        $this->app->instance(ConstitutionalEngine::class, $engine);
        $out = (new \ReflectionMethod(GovernanceStage::class, 'growCommittees'))->invoke(null, $legislature, collect(), new User);
        $this->assertSame(['created' => 0, 'target' => 1, 'existing' => 2, 'skipped' => 'at target'], $out);
        $this->assertSame(2, DB::table('committees')->count());
    }
}
