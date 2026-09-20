<?php

namespace Tests\Feature;

use App\Models\SimRun;
use App\Services\Demo\Stages\VerifyStage;
use App\Support\SimTimer;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Verification read bounds and unchanged verdicts in a guarded nonce database. */
class Phase11PerformanceTest extends TestCase
{
    private ?string $fixture = null;
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') {
            $this->markTestSkipped('Opt in to the disposable Phase 11 database.');
        }
        $this->original = DB::getDefaultConnection();
        config(['database.connections.phase11_admin' => array_replace(config('database.connections.pgsql'),
            ['database' => 'postgres', 'name' => 'phase11_admin', 'url' => null])]);
        $admin = DB::connection('phase11_admin');
        $this->assertSame('postgres', $admin->selectOne('SELECT current_database() AS name')->name);
        $this->fixture = 'phase11_test_'.bin2hex(random_bytes(8));
        $admin->statement('CREATE DATABASE '.$this->fixture.' TEMPLATE template0');
        config(['database.connections.phase11_test' => array_replace($admin->getConfig(),
            ['database' => $this->fixture, 'name' => 'phase11_test', 'url' => null])]);
        DB::setDefaultConnection('phase11_test');
        $this->assertSame($this->fixture, DB::selectOne('SELECT current_database() AS name')->name);
        $model = new SimRun;
        $this->assertSame('phase11_test', $model->getConnection()->getName());
        $this->assertSame($this->fixture, $model->getConnection()->selectOne('SELECT current_database() AS name')->name);
        DB::statement("SET lock_timeout = '3s'");
        DB::statement("SET statement_timeout = '20s'");
        DB::statement('CREATE TABLE sim_runs (id uuid PRIMARY KEY, options jsonb)');
        DB::statement('CREATE TABLE jurisdiction_cohorts (jurisdiction_id uuid, version integer, population bigint, PRIMARY KEY(jurisdiction_id, version))');
        DB::statement('CREATE TABLE sim_items (run_id uuid, kind text, unit_key text, status text, metrics jsonb)');
        DB::statement('CREATE TABLE legislatures (id uuid PRIMARY KEY, jurisdiction_id uuid, total_seats integer, status text, deleted_at timestamptz)');
        DB::statement('CREATE INDEX legislatures_jurisdiction_id_index ON legislatures(jurisdiction_id)');
        DB::statement('CREATE TABLE legislature_members (id uuid PRIMARY KEY, legislature_id uuid, status text, deleted_at timestamptz, vacated_at timestamptz)');
        DB::statement('CREATE INDEX legislature_members_legislature_id_index ON legislature_members(legislature_id)');
        DB::statement('CREATE TABLE executives (id uuid PRIMARY KEY, jurisdiction_id uuid, status text, deleted_at timestamptz)');
        DB::statement('CREATE TABLE judiciaries (id uuid PRIMARY KEY, jurisdiction_id uuid, status text, judge_count integer, min_judges integer, deleted_at timestamptz)');
        DB::statement('CREATE TABLE organizations (id uuid PRIMARY KEY, jurisdiction_id uuid, ownership_type text, board_id uuid, deleted_at timestamptz)');
        DB::statement('CREATE INDEX organizations_jurisdiction_id_index ON organizations(jurisdiction_id)');
        DB::statement('CREATE TABLE boards (id uuid PRIMARY KEY, status text, owner_seats integer, chair_seat_id uuid, deleted_at timestamptz)');
        $this->resetTimers();
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            DB::disableQueryLog();
            $this->resetTimers();
            while (DB::transactionLevel() > 0) { DB::rollBack(); }
            DB::setDefaultConnection($this->original);
            DB::purge('phase11_test');
            if (! preg_match('/^phase11_test_[a-f0-9]{16}$/D', $this->fixture)) {
                throw new \LogicException('Unexpected fixture database.');
            }
            DB::connection('phase11_admin')->statement('DROP DATABASE '.$this->fixture.' WITH (FORCE)');
            DB::purge('phase11_admin');
        }
        parent::tearDown();
    }

    private function resetTimers(): void
    {
        foreach (['us', 'n', 'max', 'open'] as $property) {
            (new \ReflectionProperty(SimTimer::class, $property))->setValue(null, []);
        }
    }

    private function id(int $number): string { return sprintf('11000000-0000-4000-8000-%012d', $number); }

    private function runWith(array $aspects): string
    {
        DB::table('sim_runs')->insert(['id' => $this->id(1), 'options' => json_encode(['scope_aspects' => $aspects])]);

        return $this->id(1);
    }

    private function legislature(int $id, int $seats, int $jurisdiction = 2, ?string $deleted = null): void
    {
        DB::table('legislatures')->insert(['id' => $this->id($id), 'jurisdiction_id' => $this->id($jurisdiction),
            'total_seats' => $seats, 'status' => 'active', 'deleted_at' => $deleted]);
    }

    private function member(int $id, int $legislature, ?string $status = 'seated', ?string $deleted = null, ?string $vacated = null): void
    {
        DB::table('legislature_members')->insert(['id' => $this->id($id), 'legislature_id' => $this->id($legislature),
            'status' => $status, 'deleted_at' => $deleted, 'vacated_at' => $vacated]);
    }

    private function completeChamber(): void
    {
        $this->legislature(10, 5);
        foreach ([100, 101, 102] as $member) { $this->member($member, 10); }
    }

    private function organization(int $id, ?string $ownership, ?int $board, int $jurisdiction = 2, ?string $deleted = null): void
    {
        DB::table('organizations')->insert(['id' => $this->id($id), 'jurisdiction_id' => $this->id($jurisdiction),
            'ownership_type' => $ownership, 'board_id' => $board === null ? null : $this->id($board), 'deleted_at' => $deleted]);
    }

    private function board(int $id, ?string $status, ?int $owners, ?int $chair = null, ?string $deleted = null): void
    {
        DB::table('boards')->insert(['id' => $this->id($id), 'status' => $status, 'owner_seats' => $owners,
            'chair_seat_id' => $chair === null ? null : $this->id($chair), 'deleted_at' => $deleted]);
    }

    private function measured(?string $runId = null, ?\Closure $beat = null): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            return [VerifyStage::run($this->id(2), $runId, 1, $beat), DB::getQueryLog()];
        } finally {
            DB::disableQueryLog();
        }
    }

    private function queriesFor(array $queries, string $table): array
    {
        return array_values(array_filter($queries, static fn ($query) => str_contains($query['query'], 'from "'.$table.'"')));
    }

    private function assertReview(array $metrics, array $gaps): void
    {
        $this->assertSame($gaps, $metrics['gaps']);
        $this->assertSame('review', $metrics['_verdict']);
        $this->assertSame(implode('; ', array_slice($gaps, 0, 6)), $metrics['_reason']);
    }

    public function test_member_counts_keep_status_deleted_vacated_and_zero_seat_semantics(): void
    {
        $run = $this->runWith(['elections']);
        foreach ([[10, 0], [11, -2], [12, 5], [13, 7], [14, 9]] as [$id, $seats]) { $this->legislature($id, $seats); }
        $this->legislature(15, 99, 2, '2026-09-20');
        $this->legislature(16, 99, 3);
        $this->member(100, 12, 'elected');
        $this->member(101, 12, 'seated', '2026-09-20');
        $this->member(102, 12, 'seated', null, '2026-09-20');
        $this->member(103, 12, 'vacant');
        $this->member(104, 12, null);
        $this->member(105, 13, 'elected');
        $this->member(106, 13, 'seated');
        $this->member(107, 10);
        $this->member(108, 15);
        $this->member(109, 16);

        [$metrics, $queries] = $this->measured($run);
        $gaps = ['legislature has zero seats (chamber not sized)', 'legislature has zero seats (chamber not sized)',
            'legislature seated 2/7, below the majority of 4', 'legislature seated 0/9, below the majority of 5'];
        $this->assertSame(['jurisdiction_id' => $this->id(2), 'aspects' => ['base', 'elections'], 'legislatures' => 5,
            'seats' => 21, 'seated' => 5, 'gaps' => $gaps, '_verdict' => 'review', '_reason' => implode('; ', $gaps)], $metrics);
        $counts = $this->queriesFor($queries, 'legislature_members');
        $this->assertCount(1, $counts);
        $this->assertSame([$this->id(12), $this->id(13), $this->id(14), 'elected', 'seated'], $counts[0]['bindings']);
    }

    public function test_civic_aggregate_preserves_null_empty_shared_deleted_and_missing_board_rules(): void
    {
        $this->completeChamber();
        $this->board(20, 'active', 2);
        $this->board(21, 'dissolved', 2);
        $this->board(22, null, 2);
        $this->board(23, 'active', 0);
        $this->board(24, 'active', -1);
        $this->board(25, 'active', 2, 99);
        $this->board(26, 'active', 2, null, '2026-09-20');
        $this->board(27, 'active', null);
        foreach ([[200, null, 20], [201, '', 20], [202, ' ', 21], [203, 'stock', 22], [204, 'stock', 23],
            [205, 'stock', 24], [206, 'stock', 25], [207, 'stock', 26], [208, 'stock', 27],
            [209, null, 999], [210, 'stock', null]] as [$id, $ownership, $board]) {
            $this->organization($id, $ownership, $board);
        }
        $this->organization(211, null, 20, 2, '2026-09-20');
        $this->organization(212, null, 20, 3);

        [$metrics, $queries] = $this->measured();
        $this->assertSame(11, $metrics['organizations']);
        $this->assertSame(3, $metrics['orgs_missing_ownership']);
        $this->assertSame(4, $metrics['boards_missing_chair']);
        $this->assertReview($metrics, ['3 organization(s) with no ownership structure', '4 board(s) with an unfilled chair']);
        $civics = $this->queriesFor($queries, 'organizations');
        $this->assertCount(1, $civics);
        $this->assertSame([$this->id(2)], $civics[0]['bindings']);
        $this->assertSame([], $this->queriesFor($queries, 'boards'));
    }

    public function test_governance_gap_order_and_reason_limit_stay_unchanged(): void
    {
        $this->legislature(10, 0);
        $this->legislature(11, 5);
        foreach ([[20, 'forming'], [21, null], [22, 'delegated'], [23, 'elected']] as [$id, $status]) {
            DB::table('executives')->insert(['id' => $this->id($id), 'jurisdiction_id' => $this->id(2), 'status' => $status]);
        }
        DB::table('executives')->insert(['id' => $this->id(24), 'jurisdiction_id' => $this->id(2), 'status' => 'forming', 'deleted_at' => '2026-09-20']);
        foreach ([[30, 'forming', 0, 5], [31, 'appointed', 2, 5], [32, 'elected', 5, 5]] as [$id, $status, $judges, $min]) {
            DB::table('judiciaries')->insert(['id' => $this->id($id), 'jurisdiction_id' => $this->id(2),
                'status' => $status, 'judge_count' => $judges, 'min_judges' => $min]);
        }
        $this->board(40, 'active', 2);
        $this->organization(50, null, 40);
        [$metrics] = $this->measured();

        $this->assertSame(4, $metrics['executives']);
        $this->assertSame(3, $metrics['judiciaries']);
        $this->assertReview($metrics, ['legislature has zero seats (chamber not sized)',
            'legislature seated 0/5, below the majority of 3', "executive status 'forming', not delegated or elected",
            "executive status '', not delegated or elected", "judiciary status 'forming', not appointed or elected",
            'judiciary has 2 judges, below the minimum of 5', '1 organization(s) with no ownership structure',
            '1 board(s) with an unfilled chair']);
    }

    public function test_empty_organization_and_governance_lists_keep_zero_metrics_without_gaps(): void
    {
        $this->completeChamber();
        [$metrics] = $this->measured();
        $this->assertSame(['jurisdiction_id' => $this->id(2), 'aspects' => SimRun::ALL_ASPECTS,
            'legislatures' => 1, 'seats' => 5, 'seated' => 3, 'executives' => 0, 'judiciaries' => 0,
            'organizations' => 0, 'orgs_missing_ownership' => 0, 'boards_missing_chair' => 0, '_verdict' => 'done'], $metrics);
    }

    public function test_no_or_only_unsized_legislatures_do_not_read_members(): void
    {
        $run = $this->runWith(['elections']);
        [$empty, $queries] = $this->measured($run);
        $this->assertSame(0, $empty['legislatures']);
        $this->assertSame(0, $empty['seats']);
        $this->assertSame(0, $empty['seated']);
        $this->assertReview($empty, ['no legislature for a chamber-bearing scope']);
        $this->assertSame([], $this->queriesFor($queries, 'legislature_members'));
        $this->legislature(10, 0);
        $this->member(100, 10);
        [$unsized, $queries] = $this->measured($run);
        $this->assertSame(0, $unsized['seated']);
        $this->assertReview($unsized, ['legislature has zero seats (chamber not sized)']);
        $this->assertSame([], $this->queriesFor($queries, 'legislature_members'));
    }

    public function test_aspect_skips_and_unknown_run_fallback_preserve_the_scan(): void
    {
        $run = $this->runWith(['base']);
        $this->completeChamber();
        $this->organization(20, null, null);
        $beats = 0;
        [$base, $queries] = $this->measured($run, function () use (&$beats) { $beats++; });
        $this->assertSame(['jurisdiction_id' => $this->id(2), 'aspects' => ['base'], '_verdict' => 'done'], $base);
        $this->assertCount(3, $queries);
        $this->assertSame(3, $beats);
        $this->assertArrayNotHasKey('verify.elections_read', (new \ReflectionProperty(SimTimer::class, 'n'))->getValue());
        $this->assertArrayNotHasKey('verify.civics_read', (new \ReflectionProperty(SimTimer::class, 'n'))->getValue());
        DB::table('sim_runs')->where('id', $run)->update(['options' => json_encode(['scope_aspects' => ['elections']])]);
        [$elections, $queries] = $this->measured($run);
        $this->assertSame('done', $elections['_verdict']);
        foreach (['organizations', 'executives', 'judiciaries'] as $table) {
            $this->assertSame([], $this->queriesFor($queries, $table));
        }
        [$unknown] = $this->measured($this->id(999));
        $this->assertSame(SimRun::ALL_ASPECTS, $unknown['aspects']);
        $this->assertReview($unknown, ['1 organization(s) with no ownership structure']);
    }

    public function test_lawful_inactive_decisions_still_short_circuit_only_eligible_scopes(): void
    {
        $run = $this->runWith(['elections']);
        DB::table('jurisdiction_cohorts')->insert(['jurisdiction_id' => $this->id(2), 'version' => 1, 'population' => 0]);
        $this->legislature(10, 0);
        $this->legislature(11, 5, 2, '2026-09-20');
        $beats = 0;
        [$zero, $queries] = $this->measured($run, function () use (&$beats) { $beats++; });
        $this->assertSame(['jurisdiction_id' => $this->id(2), 'aspects' => ['base', 'elections'],
            'inactive' => 'zero_population', '_verdict' => 'done'], $zero);
        $this->assertCount(3, $queries);
        $this->assertSame(1, $beats);
        $this->assertSame([], $this->queriesFor($queries, 'legislature_members'));

        DB::table('legislatures')->where('id', $this->id(10))->update(['total_seats' => 5]);
        [$sized] = $this->measured($run);
        $this->assertReview($sized, ['legislature seated 0/5, below the majority of 3']);
        $this->assertArrayNotHasKey('inactive', $sized);
        DB::table('jurisdiction_cohorts')->update(['population' => 4]);
        DB::table('sim_items')->insert(['run_id' => $run, 'kind' => 'election_scope', 'unit_key' => $this->id(2),
            'status' => 'review', 'metrics' => json_encode(['inactive' => 'too_few_residents'])]);
        [$unsettled] = $this->measured($run);
        $this->assertReview($unsettled, ['legislature seated 0/5, below the majority of 3']);
        DB::table('sim_items')->update(['status' => 'done']);
        [$inactive, $queries] = $this->measured($run);
        $this->assertSame(['jurisdiction_id' => $this->id(2), 'aspects' => ['base', 'elections'],
            'inactive' => 'too_few_residents', '_verdict' => 'done'], $inactive);
        $this->assertCount(3, $queries);
        DB::table('sim_items')->update(['metrics' => json_encode(['inactive' => 'other_reason'])]);
        [$other] = $this->measured($run);
        $this->assertReview($other, ['legislature seated 0/5, below the majority of 3']);
    }

    public function test_large_scope_uses_fixed_read_count_and_one_civic_result_row(): void
    {
        $run = $this->runWith(SimRun::ALL_ASPECTS);
        $this->board(20, 'active', 2);
        $legs = $members = $orgs = [];
        for ($i = 0; $i < 200; $i++) {
            $legs[] = ['id' => $this->id(1000 + $i), 'jurisdiction_id' => $this->id(2), 'total_seats' => 5, 'status' => 'active'];
            for ($j = 0; $j < 3; $j++) {
                $members[] = ['id' => $this->id(2000 + 3 * $i + $j), 'legislature_id' => $this->id(1000 + $i), 'status' => 'seated'];
            }
        }
        for ($i = 0; $i < 3000; $i++) {
            $orgs[] = ['id' => $this->id(10000 + $i), 'jurisdiction_id' => $this->id(2), 'ownership_type' => 'stock', 'board_id' => $this->id(20)];
        }
        DB::table('legislatures')->insert($legs);
        DB::table('legislature_members')->insert($members);
        foreach (array_chunk($orgs, 500) as $chunk) { DB::table('organizations')->insert($chunk); }
        // Unrelated organizations/boards make a scope-first plan observable.
        for ($batch = 0; $batch < 20; $batch++) {
            $otherOrgs = $otherBoards = [];
            for ($i = 0; $i < 500; $i++) {
                $id = 20000 + $batch * 500 + $i;
                $otherBoards[] = ['id' => $this->id($id), 'status' => 'active', 'owner_seats' => 2];
                $otherOrgs[] = ['id' => $this->id($id), 'jurisdiction_id' => $this->id(3), 'ownership_type' => null, 'board_id' => $this->id($id)];
            }
            DB::table('boards')->insert($otherBoards);
            DB::table('organizations')->insert($otherOrgs);
        }
        DB::statement('ANALYZE organizations');
        DB::statement('ANALYZE boards');
        [$metrics, $queries] = $this->measured($run);
        $this->assertSame(200, $metrics['legislatures']);
        $this->assertSame(1000, $metrics['seats']);
        $this->assertSame(600, $metrics['seated']);
        $this->assertSame(3000, $metrics['organizations']);
        $this->assertSame(0, $metrics['orgs_missing_ownership']);
        $this->assertSame(3000, $metrics['boards_missing_chair']);
        $this->assertReview($metrics, ['3000 board(s) with an unfilled chair']);
        $this->assertCount(8, $queries); // Previously 208: 200 separate member counts and two civic reads.
        $counts = $this->queriesFor($queries, 'legislature_members');
        $this->assertCount(1, $counts);
        $this->assertCount(202, $counts[0]['bindings']); // 200 selected chamber IDs and two statuses.
        $civics = $this->queriesFor($queries, 'organizations');
        $this->assertCount(1, $civics);
        $this->assertStringContainsString('COUNT(*) AS organizations', $civics[0]['query']);
        $this->assertStringContainsString('SUM(CASE', $civics[0]['query']);
        $rows = DB::select($civics[0]['query'], $civics[0]['bindings']);
        $this->assertCount(1, $rows);
        $this->assertSame(['organizations', 'missing_ownership', 'missing_chair'], array_keys((array) $rows[0]));
        $this->assertSame([], $this->queriesFor($queries, 'boards'));
        $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$civics[0]['query'], $civics[0]['bindings']);
        $planJson = $plan[0]->{'QUERY PLAN'};
        $this->assertStringContainsString('organizations_jurisdiction_id_index', $planJson);
        $this->assertStringContainsString('boards_pkey', $planJson);
        $timers = (new \ReflectionProperty(SimTimer::class, 'n'))->getValue();
        foreach (['verify.scan', 'verify.elections_read', 'verify.civics_read'] as $part) { $this->assertSame(1, $timers[$part]); }
    }
}
