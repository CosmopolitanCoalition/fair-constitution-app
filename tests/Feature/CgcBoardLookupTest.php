<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ClockTimer;
use App\Models\Term;
use App\Services\ClockService;
use App\Services\Demo\SimBoardService;
use App\Services\Organizations\CoDeterminationService;
use App\Services\Organizations\OrgBoardService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use App\Support\SimTimer;
use Illuminate\Support\Facades\DB;
use Tests\Support\Step5BoardDatabase;
use Tests\TestCase;

class CgcBoardLookupTest extends TestCase
{
    use Step5BoardDatabase;

    private SimBoardService $service;

    private ClockService $clocks;

    private ?string $oldChunk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBoardFixture();
        // This fixture pins bounded board lookup/term behavior. Real chair
        // voting is covered by BoardChairWorkflowTest and SimRepairIntegrationTest.
        $chairs = $this->createMock(\App\Services\Demo\SimChairService::class);
        $chairs->method('complete')->willReturn(['status' => 'done']);
        $this->app->instance(\App\Services\Demo\SimChairService::class, $chairs);
        $this->oldChunk = getenv('CGA_SWEEP_CHUNK') === false ? null : getenv('CGA_SWEEP_CHUNK');
        putenv('CGA_SWEEP_CHUNK=2');
        $_ENV['CGA_SWEEP_CHUNK'] = '2';
        $settings = $this->createMock(SettingsResolver::class);
        $settings->method('resolveInt')->with($this->id(1), 'civil_appointment_years', 10)->willReturn(7);
        $this->clocks = $this->createMock(ClockService::class);
        $this->service = new SimBoardService($this->createMock(OrgBoardService::class),
            $this->createMock(CoDeterminationService::class), $this->createMock(RoleService::class),
            $settings, $this->clocks);
        $this->travelTo(now()->setDate(2026, 9, 20)->startOfDay());
    }

    protected function tearDown(): void
    {
        if (isset($this->service)) {
            putenv($this->oldChunk === null ? 'CGA_SWEEP_CHUNK' : 'CGA_SWEEP_CHUNK='.$this->oldChunk);
            if ($this->oldChunk === null) {
                unset($_ENV['CGA_SWEEP_CHUNK']);
            } else {
                $_ENV['CGA_SWEEP_CHUNK'] = $this->oldChunk;
            }
        }
        foreach (['us', 'n', 'max', 'open'] as $property) {
            (new \ReflectionProperty(SimTimer::class, $property))->setValue(null, []);
        }
        $this->closeBoardFixture();
        parent::tearDown();
    }

    private function board(int $org, array $organization = [], array $board = []): string
    {
        DB::table('organizations')->insert(array_replace(['id' => $this->id($org),
            'jurisdiction_id' => $this->id(1), 'type' => 'common_good_corp'], $organization));
        // Reverse board IDs intentionally: order is by organization, not heap/board id.
        $boardId = $this->id(10000 - $org);
        DB::table('boards')->insert(array_replace(['id' => $boardId, 'boardable_type' => 'organizations',
            'boardable_id' => $this->id($org), 'status' => 'forming'], $board));

        return $boardId;
    }

    private function fetched(string $jurisdiction): array
    {
        return iterator_to_array((new \ReflectionMethod(SimBoardService::class, 'cgcBoards'))
            ->invoke($this->service, $jurisdiction, null), false);
    }

    public function test_exact_old_eligible_set_preserves_relation_pointer_inactive_and_deletion_semantics(): void
    {
        $expected = [$this->board(2), $this->board(3, ['board_id' => $this->id(999)]),
            $this->board(4, ['is_active' => false]), $this->board(5, ['board_id' => null])];
        $this->board(6, ['deleted_at' => now()]);
        $this->board(7, [], ['deleted_at' => now()]);
        $this->board(8, [], ['status' => 'dissolved']);
        $this->board(9, ['jurisdiction_id' => $this->id(999)]);
        $this->board(10, ['type' => 'business']);
        $this->board(11, [], ['boardable_type' => 'departments']);
        $old = Board::query()->join('organizations as o', fn ($join) => $join->on('o.id', '=', 'boards.boardable_id')
            ->where('boards.boardable_type', 'organizations'))->where('o.jurisdiction_id', $this->id(1))
            ->where('o.type', 'common_good_corp')->whereNull('o.deleted_at')
            ->where('boards.status', '!=', 'dissolved')->pluck('boards.id')->all();
        $actual = array_map(fn ($board) => $board->id, $this->fetched($this->id(1)));
        $this->assertEqualsCanonicalizing($old, $actual);
        $this->assertSame($expected, $actual);
        $this->assertSame([], $this->fetched($this->id(100)));
        $this->assertSame(0, $this->service->seatCgcGovernors($this->id(1))); // no holders
        $this->assertSame(0, DB::table('terms')->count());
    }

    public function test_multiple_boards_rotate_executive_first_holders_across_pages_and_preserve_seated_rows(): void
    {
        $boards = [$this->board(2), $this->board(4, ['is_active' => false]), $this->board(6)];
        foreach ([999, 801, 802] as $holder) {
            DB::table('users')->insert(['id' => $this->id($holder), 'email' => 'sim-'.$holder.'@demo.invalid']);
            DB::table('residency_confirmations')->insert(['user_id' => $this->id($holder),
                'jurisdiction_id' => $this->id(1), 'is_active' => true]);
        }
        DB::table('executives')->insert(['id' => $this->id(700), 'jurisdiction_id' => $this->id(1)]);
        DB::table('executive_members')->insert(['executive_id' => $this->id(700),
            'user_id' => $this->id(999), 'status' => 'seated']);
        $seats = [];
        foreach ($boards as $index => $board) {
            foreach (range(1, $index + 1) as $number) {
                $seat = $this->id(2000 + count($seats));
                $seats[] = $seat;
                DB::table('board_seats')->insert(['id' => $seat, 'board_id' => $board,
                    'seat_class' => 'governor', 'seat_no' => $number, 'status' => 'vacant']);
            }
        }
        DB::table('board_seats')->insert(['id' => $this->id(3000), 'board_id' => $boards[0], 'seat_class' => 'governor',
            'seat_no' => 99, 'status' => 'seated', 'holder_user_id' => $this->id(701), 'term_id' => $this->id(702)]);
        DB::table('board_seats')->insert(['id' => $this->id(3001), 'board_id' => $boards[1],
            'seat_class' => 'owner_elected', 'seat_no' => 99, 'status' => 'vacant']);
        DB::table('board_seats')->insert(['id' => $this->id(3002), 'board_id' => $boards[2],
            'seat_class' => 'governor', 'seat_no' => 99, 'status' => 'vacant', 'deleted_at' => now()]);
        $this->clocks->expects($this->exactly(6))->method('arm')->willReturnCallback(
            function ($clock, $jurisdiction, $type, $id, $expires, $payload) {
                $this->assertSame('CLK-09', $clock);
                $this->assertSame($this->id(1), $jurisdiction);
                $this->assertSame('term', $type);
                $this->assertSame('2033-09-20', $expires->toDateString());
                $term = Term::findOrFail($id);
                $this->assertSame('civil_appointment', $term->term_class);
                $this->assertSame($term->ends_on->toDateString(), $payload['ends_on']);

                return new ClockTimer;
            });
        $this->assertSame(6, $this->service->seatCgcGovernors($this->id(1)));
        $this->assertSame(array_map($this->id(...), [999, 801, 802, 999, 801, 802]),
            BoardSeat::whereIn('id', $seats)->orderBy('id')->pluck('holder_user_id')->all());
        $this->assertSame($this->id(702), BoardSeat::findOrFail($this->id(3000))->term_id);
        $this->assertSame('vacant', BoardSeat::findOrFail($this->id(3001))->status);
        $this->assertSame(0, $this->service->seatCgcGovernors($this->id(1)));
        $this->assertSame(6, Term::count());
        $this->assertSame(3, Board::where('composition_valid', true)->count());
        $timers = (new \ReflectionProperty(SimTimer::class, 'n'))->getValue();
        $this->assertGreaterThanOrEqual(4, $timers['civics.cgc_board_lookup']);
    }

    public function test_stale_department_only_statistics_cannot_expand_lookup_to_unrelated_boards(): void
    {
        $this->seedStaleBoards(20000);
        $distribution = DB::selectOne("SELECT most_common_vals::text AS values FROM pg_stats
            WHERE schemaname='public' AND tablename='boards' AND attname='boardable_type'");
        $this->assertSame('{departments}', $distribution->values);
        $scope = DB::table('organizations')->orderBy('id')->first();
        $ids = DB::table('organizations')->orderBy('id')->limit(3)->pluck('id');
        DB::table('organizations')->whereIn('id', $ids)->update(['jurisdiction_id' => $scope->jurisdiction_id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $boards = $this->fetched($scope->jurisdiction_id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $boards);
        $this->assertSame($ids->all(), array_map(fn ($board) => $board->boardable_id, $boards));
        $boardReads = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], 'from "boards"')));
        $this->assertCount(2, $boardReads);
        foreach ($boardReads as $query) {
            $plan = DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query['query'], $query['bindings']);
            $nodes = $this->nodes(json_decode($plan->{'QUERY PLAN'}, true)[0]['Plan']);
            $probes = array_values(array_filter($nodes, fn ($n) => ($n['Index Name'] ?? '') === 'boards_one_per_body'));
            $this->assertCount(1, $probes);
            $this->assertStringContainsString('boardable_type', $probes[0]['Index Cond']);
            $this->assertStringContainsString('boardable_id', $probes[0]['Index Cond']);
            $this->assertLessThanOrEqual(2, $probes[0]['Actual Rows']);
            $this->assertSame(0, $probes[0]['Rows Removed by Filter'] ?? 0);
        }
    }

    private function nodes(array $node): array
    {
        $nodes = [$node];
        foreach ($node['Plans'] ?? [] as $child) {
            array_push($nodes, ...$this->nodes($child));
        }

        return $nodes;
    }
}
