<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Legislature;
use App\Services\AuditService;
use App\Services\Demo\SimBoardService;
use App\Services\Demo\Stages\CivicsStage;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Private in-memory SQL fixtures: no migrations or connection to a world database. */
class Phase9PerformanceTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.phase9_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('phase9_fixture');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('phase9_fixture', (new Legislature)->getConnection()->getName());
        DB::statement('CREATE TABLE jurisdictions (id text PRIMARY KEY, parent_id text, name text, population integer, deleted_at text)');
        DB::statement('CREATE TABLE legislatures (id text PRIMARY KEY, jurisdiction_id text, total_seats integer, deleted_at text)');
        DB::statement('CREATE TABLE legislature_members (id text PRIMARY KEY, legislature_id text, user_id text, status text, vacated_at text, deleted_at text)');
        DB::statement('CREATE TABLE organizations (id text PRIMARY KEY, jurisdiction_id text, type text, name text, slug text, worker_count integer, is_active boolean, is_registered boolean, registered_at text, agent_user_id text, created_at text, updated_at text, deleted_at text)');
        DB::statement('CREATE TABLE elections (id text PRIMARY KEY, jurisdiction_id text, kind text, status text, created_at text)');
        DB::statement('CREATE TABLE candidacies (id text PRIMARY KEY, election_id text)');
        DB::statement('CREATE TABLE endorsements (id text PRIMARY KEY, election_id text, candidate_id text, endorser_type text, endorser_id text, statement text, endorsed_at text, is_active boolean, is_public boolean, created_at text, updated_at text)');
        DB::statement('CREATE TABLE users (id text PRIMARY KEY, email text)');
        DB::statement('CREATE TABLE residency_confirmations (user_id text, jurisdiction_id text, is_active boolean)');
        DB::statement('CREATE TABLE committees (id text PRIMARY KEY, legislature_id text, created_at text, deleted_at text)');
        DB::statement('CREATE TABLE bills (id text PRIMARY KEY, legislature_id text, jurisdiction_id text, sponsor_member_id text, title text, act_type text, scale text, status text, committee_id text, current_version_no integer, introduced_at text, created_at text, updated_at text, deleted_at text)');
        DB::statement('CREATE TABLE bill_versions (id text PRIMARY KEY, bill_id text, version_no integer, law_text text, changed_by_member_id text, change_kind text, created_at text)');
        $boards = $this->createMock(SimBoardService::class);
        $boards->method('seedBusinessBoards')->willReturn(['boards' => 0, 'workers' => 0, 'owner_seats' => 0, 'worker_seats' => 0]);
        $boards->method('seatCgcGovernors')->willReturn(0);
        $this->app->instance(SimBoardService::class, $boards);
        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn(new AuditEntry);
        $this->app->instance(AuditService::class, $audit);
        config(['cga.sim_civics' => ['parties_min' => 2, 'parties_max' => 8, 'nonprofit_per' => 180,
            'business_per' => 10, 'org_sample' => 1000, 'bills_per_member' => 20, 'bill_sample' => 1000]]);
    }

    protected function tearDown(): void
    {
        DB::purge('phase9_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function organizations(string $type, int $count, string $jurisdiction = 'scope', ?string $deleted = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('organizations')->insert(['id' => $jurisdiction.'-'.$type.'-'.$i.($deleted ? '-deleted' : ''),
                'jurisdiction_id' => $jurisdiction, 'type' => $type, 'deleted_at' => $deleted, 'is_active' => false]);
        }
    }

    public function test_one_scoped_census_preserves_every_top_up_and_rerun(): void
    {
        DB::table('jurisdictions')->insert(['id' => 'scope', 'name' => 'Fixture', 'population' => 500000]);
        DB::table('legislatures')->insert(['id' => 'chamber', 'jurisdiction_id' => 'scope', 'total_seats' => 5]);
        DB::table('legislature_members')->insert(['id' => 'member', 'legislature_id' => 'chamber', 'user_id' => 'user', 'status' => 'elected']);
        foreach (['political_party' => 1, 'nonprofit' => 1, 'business' => 60, 'common_good_corp' => 2] as $type => $count) {
            $this->organizations($type, $count);
            $this->organizations($type, 4, 'elsewhere');
            $this->organizations($type, 4, 'scope', '2026-01-01');
        }
        $this->organizations('informal', 2);
        $beats = 0;
        DB::enableQueryLog(); DB::flushQueryLog();
        $first = CivicsStage::run('scope', null, 1, function () use (&$beats) { $beats++; });
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $censuses = array_values(array_filter($queries, fn ($q) => str_contains(strtolower($q['query']), 'count(*)') && str_contains($q['query'], '"organizations"')));
        $this->assertCount(1, $censuses, 'Previously four independent per-type counts in this scope.');
        $this->assertContains('scope', $censuses[0]['bindings']);
        $this->assertSame(['true' => 3, 'minted' => 3], $first['parties']);
        $this->assertSame(['true' => 2777, 'minted' => 3], $first['nonprofits']);
        $this->assertSame(['true' => 50000, 'minted' => 60], $first['businesses']);
        $this->assertSame(2, $first['cgcs']);
        $this->assertSame(['true' => 100, 'minted' => 3], $first['bills']);
        $this->assertSame(4, $beats);
        $this->assertEqualsCanonicalizing(['Progress Party', 'Heritage Party'], DB::table('organizations')
            ->where('jurisdiction_id', 'scope')->where('type', 'political_party')->whereNotNull('name')->pluck('name')->all());
        $total = DB::table('organizations')->count();
        $beats = 0;
        $second = CivicsStage::run('scope', null, 1, function () use (&$beats) { $beats++; });
        $this->assertSame($first, $second);
        $this->assertSame($total, DB::table('organizations')->count());
        $this->assertSame(3, DB::table('bill_versions')->count());
        $this->assertSame(0, $beats);
    }

    public static function fields(): array
    {
        return [[0], [1], [2], [3], [4], [1000]];
    }

    #[DataProvider('fields')]
    public function test_endorsement_sample_matches_full_field_round_robin(int $candidateCount): void
    {
        DB::table('elections')->insert(['id' => 'open', 'jurisdiction_id' => 'scope', 'kind' => 'general', 'status' => 'approval', 'created_at' => '2026-09-20']);
        $this->organizations('business', 3);
        $this->organizations('nonprofit', 1, 'elsewhere');
        foreach (['u1', 'u2', 'u3'] as $id) {
            DB::table('users')->insert(['id' => $id, 'email' => 'sim-'.$id.'@demo.invalid']);
            DB::table('residency_confirmations')->insert(['user_id' => $id, 'jurisdiction_id' => 'scope', 'is_active' => true]);
        }
        for ($i = $candidateCount - 1; $i >= 0; $i--) {
            DB::table('candidacies')->insert(['id' => sprintf('c%04d', $i), 'election_id' => 'open']);
        }
        DB::table('candidacies')->insert(['id' => 'a-other-election', 'election_id' => 'other']);
        DB::enableQueryLog(); DB::flushQueryLog();
        $invoke = fn () => (new \ReflectionMethod(CivicsStage::class, 'mintEndorsements'))->invoke(null, (object) ['id' => 'scope'], null);
        $count = $invoke();
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame($candidateCount === 0 ? 0 : 4, $count);
        $candidateQueries = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], '"candidacies"')));
        $this->assertCount(1, $candidateQueries);
        $this->assertStringContainsString('order by "id" asc limit 4', $candidateQueries[0]['query']);
        $expected = [];
        if ($candidateCount > 0) {
            foreach (['scope-business-0', 'scope-business-1', 'u1', 'u2'] as $i => $endorser) {
                $expected[$endorser] = sprintf('c%04d', $i % $candidateCount);
            }
        }
        $this->assertEquals($expected, DB::table('endorsements')->pluck('candidate_id', 'endorser_id')->all());
        $this->assertSame($count, $invoke());
        $this->assertSame($count, DB::table('endorsements')->count());
    }

    public function test_empty_non_chamber_scope_skips_the_organization_census(): void
    {
        DB::table('jurisdictions')->insert(['id' => 'scope', 'name' => 'Empty', 'population' => 0]);
        DB::enableQueryLog(); DB::flushQueryLog();
        $result = CivicsStage::run('scope', null, 1);
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame(0, $result['businesses']['minted']);
        $this->assertSame([], array_values(array_filter($queries, fn ($q) => str_contains($q['query'], '"organizations"'))));
    }
}
