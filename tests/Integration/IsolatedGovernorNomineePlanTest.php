<?php

namespace Tests\Integration;

use App\Support\GovernorNomineeDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Opt-in, empty disposable PostgreSQL database only. Never use a world database. */
final class IsolatedGovernorNomineePlanTest extends TestCase
{
    private ?string $original = null;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('WOS_GOVERNOR_FIXTURE_DB');
        if (! is_string($database) || ! preg_match('/\Acodex_governors_[a-f0-9]{12}\z/', $database)) {
            $this->markTestSkipped('Requires an explicitly created empty codex_governors_<12 hex> database.');
        }
        $this->original = DB::getDefaultConnection();
        $connection = config('database.connections.pgsql');
        unset($connection['url']);
        config(['database.connections.governor_plan_fixture' => array_replace($connection,
            ['driver' => 'pgsql', 'database' => $database, 'search_path' => 'public'])]);
        DB::setDefaultConnection('governor_plan_fixture');
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertSame($database, DB::selectOne('select current_database() as name')->name);
        self::assertSame(0, (int) DB::selectOne("select count(*) as n from information_schema.tables where table_schema='public'")->n);
        DB::beginTransaction();
        DB::statement("SET LOCAL statement_timeout = '10s'");
        DB::statement("SET LOCAL lock_timeout = '3s'");
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->text('display_name')->nullable();
            $t->text('name')->nullable();
            $t->softDeletes();
        });
        $schema->create('social_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->unique();
            $t->text('display_name')->nullable();
            $t->text('handle')->nullable();
            $t->string('visibility');
            $t->softDeletes();
        });
        $schema->create('residency_confirmations', function (Blueprint $t) {
            $t->uuid('user_id');
            $t->uuid('jurisdiction_id');
            $t->boolean('is_active');
        });
        // Same active association index as the flattened baseline, plus the
        // exact production prefix index definitions. No planner knobs changed.
        DB::statement('CREATE UNIQUE INDEX residency_confirmations_user_jur_active_unique ON residency_confirmations (user_id, jurisdiction_id) WHERE is_active');
        $migration = require base_path('database/migrations/2026_09_13_101000_governor_nominee_directory_indexes.php');
        foreach ((new \ReflectionClass($migration))->getConstant('INDEXES') as $name => $definition) {
            DB::statement('CREATE INDEX '.$name.' '.$definition);
        }
        DB::statement("INSERT INTO users (id, display_name, name) SELECT ('72000000-0000-4000-8000-' || lpad(g::text,12,'0'))::uuid,
            CASE WHEN g BETWEEN 100 AND 159 THEN 'Same Name' WHEN g BETWEEN 200 AND 259 THEN NULL ELSE 'Person ' || lpad(g::text,6,'0') END,
            'Private legal ' || g FROM generate_series(1,20000) g");
        DB::statement("INSERT INTO social_profiles (id,user_id,display_name,handle,visibility) SELECT
            ('73000000-0000-4000-8000-' || lpad(g::text,12,'0'))::uuid, ('72000000-0000-4000-8000-' || lpad(g::text,12,'0'))::uuid,
            CASE WHEN g BETWEEN 200 AND 259 THEN 'Same Name' ELSE 'Social ' || lpad(g::text,6,'0') END,
            CASE WHEN g BETWEEN 600 AND 659 THEN 'nominee-' || g ELSE 'handle-' || g END, 'public' FROM generate_series(1,10000) g");
        DB::statement("INSERT INTO residency_confirmations (user_id,jurisdiction_id,is_active) SELECT
            ('72000000-0000-4000-8000-' || lpad(g::text,12,'0'))::uuid,
            CASE WHEN g % 2 = 0 THEN '74000000-0000-4000-8000-000000000001'::uuid ELSE '74000000-0000-4000-8000-000000000002'::uuid END,
            g % 17 <> 0 FROM generate_series(1,20000) g");
        foreach (['users', 'social_profiles', 'residency_confirmations'] as $table) {
            DB::statement('ANALYZE '.$table);
        }
    }

    protected function tearDown(): void
    {
        if ($this->original !== null) {
            while (DB::connection('governor_plan_fixture')->transactionLevel() > 0) {
                DB::connection('governor_plan_fixture')->rollBack();
            }
            DB::purge('governor_plan_fixture');
            DB::setDefaultConnection($this->original);
        }
        parent::tearDown();
    }

    public function test_real_public_prefix_queries_use_the_production_indexes_and_seek_beyond_the_first_page(): void
    {
        $directory = new GovernorNomineeDirectory;
        $org = '75000000-0000-4000-8000-000000000001';
        $place = '74000000-0000-4000-8000-000000000001';
        foreach (['Same', '@nominee-'] as $prefix) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $first = $directory->page(Request::create('/?nominee_q='.rawurlencode($prefix)), $org, $place);
            self::assertCount(20, $first['candidates']);
            self::assertNotNull($first['next']);
            $queries = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "users" as "u"')));
            self::assertCount($prefix === 'Same' ? 2 : 1, $queries);
            $plans = [];
            foreach ($queries as $query) {
                $json = DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query['query'], $query['bindings']);
                $plan = json_decode($json->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
                $encoded = json_encode($plan);
                $plans[] = $encoded;
                self::assertStringContainsString('governor_public_', $encoded, 'The public-name lane must use its dedicated prefix index.');
                self::assertStringContainsString('residency_confirmations_user_jur_active_unique', $encoded);
                self::assertSame('Limit', $plan['Plan']['Node Type']);
                self::assertLessThanOrEqual(21, $plan['Plan']['Actual Rows']);
                fwrite(STDOUT, "\n".json_encode(['prefix' => $prefix, 'sql' => $query['query'], 'plan' => $plan], JSON_UNESCAPED_SLASHES)."\n");
            }
            self::assertStringContainsString($prefix === 'Same' ? 'users_governor_public_name_idx' : 'profiles_governor_public_handle_idx', implode('', $plans));
            if ($prefix === 'Same') {
                self::assertStringContainsString('profiles_governor_public_name_idx', implode('', $plans));
            }
            $second = $directory->page(Request::create($first['next']), $org, $place);
            self::assertNotEmpty($second['candidates']);
            self::assertSame([], array_values(array_intersect(array_column($first['candidates'], 'id'), array_column($second['candidates'], 'id'))));
            $back = $directory->page(Request::create($second['previous']), $org, $place);
            self::assertSame(array_column($first['candidates'], 'id'), array_column($back['candidates'], 'id'));
            foreach ($second['candidates'] as $candidate) {
                self::assertStringNotContainsString('Private legal', $candidate['name']);
            }
        }
    }
}
