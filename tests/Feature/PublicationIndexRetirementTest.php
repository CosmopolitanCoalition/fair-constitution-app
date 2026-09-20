<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\DisposableRepairWorld;
use Tests\TestCase;

class PublicationIndexRetirementTest extends TestCase
{
    use DisposableRepairWorld;
    private const OLD = 'public_records_actor_user_id_index';
    private const WIDE = 'profile_publication_seek_idx';
    protected function setUp(): void
    {
        parent::setUp(); $this->openRepairWorld(); DB::commit(); $this->migration()->down();
    }
    protected function tearDown(): void { $this->closeRepairWorld(); parent::tearDown(); }
    private function migration(): object { return require base_path('database/migrations/2026_09_20_180000_retire_duplicate_publication_actor_index.php'); }
    private function exists(string $name): bool { return DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS found', [$name])->found; }
    private function refused(string $part): void
    {
        try { $this->migration()->up(); self::fail('Expected refusal'); }
        catch (\RuntimeException $e) { self::assertStringContainsString($part, $e->getMessage()); }
        self::assertTrue($this->exists(self::OLD));
    }
    public function test_actor_history_count_and_existence_keep_results_and_scoped_plans(): void
    {
        $actor = (string) Str::uuid7(); $other = (string) Str::uuid7();
        foreach ([$actor, $other] as $id) DB::table('users')->insert(['id' => $id, 'name' => 'Fixture', 'email' => $id.'@example.test', 'password' => 'unused', 'terms_accepted_at' => now()]);
        DB::statement("INSERT INTO public_records (id,kind,title,actor_user_id,published_at) SELECT gen_random_uuid(),'other','Fixture',
            CASE WHEN g<=20 THEN ?::uuid ELSE ?::uuid END,now() FROM generate_series(1,6000) g", [$actor, $other]);
        DB::statement('ANALYZE public_records'); // bounded disposable fixture, never the world
        $queries = [
            'SELECT id,seq,title FROM public_records WHERE actor_user_id=? ORDER BY seq DESC LIMIT 10',
            'SELECT count(*) AS total FROM public_records WHERE actor_user_id=?',
            'SELECT EXISTS(SELECT 1 FROM public_records WHERE actor_user_id=?) AS found',
        ];
        $before = array_map(fn ($q) => DB::select($q, [$actor]), $queries);
        $this->migration()->up(); $this->migration()->up();
        self::assertFalse($this->exists(self::OLD));
        foreach ($queries as $i => $query) {
            self::assertEquals($before[$i], DB::select($query, [$actor]));
            $plan = DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query, [$actor]);
            self::assertStringContainsString(self::WIDE, $plan->{'QUERY PLAN'});
            self::assertStringNotContainsString('Seq Scan', $plan->{'QUERY PLAN'});
        }
        $this->migration()->down(); $this->migration()->down(); self::assertTrue($this->exists(self::OLD));
    }
    public function test_replacement_and_protected_index_definitions_are_validated_before_drop(): void
    {
        DB::statement('DROP INDEX '.self::WIDE); $this->refused('replacement');
        DB::statement('CREATE INDEX '.self::WIDE.' ON public_records(actor_user_id,seq DESC) WHERE actor_user_id IS NOT NULL');
        $this->refused('Unexpected definition'); DB::statement('DROP INDEX '.self::WIDE);
        DB::statement('CREATE INDEX '.self::WIDE.' ON public_records(actor_user_id,seq DESC)');
        DB::statement("CREATE FUNCTION fixture_publication_index_dependency() RETURNS regclass LANGUAGE SQL RETURN 'public.".self::OLD."'::regclass");
        $this->refused('dependencies'); DB::statement('DROP FUNCTION fixture_publication_index_dependency()');
        DB::beginTransaction(); try { $this->refused('no open transaction'); } finally { DB::rollBack(); }
        $this->migration()->up();
    }
    public function test_concurrent_ownership_and_interrupted_drop_recover_without_losing_timeouts(): void
    {
        $cfg = DB::connection()->getConfig();
        $peer = new \PDO('pgsql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'], $cfg['username'], $cfg['password']);
        $lock = (new \ReflectionClass($this->migration()))->getConstant('LOCK');
        $peer->query('SELECT pg_advisory_lock('.$lock.')');
        try { $this->refused('Another publication index'); } finally { $peer->query('SELECT pg_advisory_unlock('.$lock.')'); }
        $peer->beginTransaction(); $peer->query('SELECT id FROM public_records LIMIT 1')->fetchAll();
        DB::statement("SET lock_timeout='50ms'");
        try { $this->migration()->up(); self::fail('Expected bounded wait'); }
        catch (\PDOException $e) { self::assertSame('55P03', $e->getCode()); }
        finally { $peer->rollBack(); }
        self::assertSame('50ms', DB::selectOne('SHOW lock_timeout')->lock_timeout);
        self::assertFalse(DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid=to_regclass(?)', [self::OLD])->indisvalid);
        $this->migration()->up(); self::assertFalse($this->exists(self::OLD));
        $this->migration()->down(); self::assertTrue($this->exists(self::OLD));
    }
}
