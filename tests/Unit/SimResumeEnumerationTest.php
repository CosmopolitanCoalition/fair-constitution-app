<?php

namespace Tests\Unit;

use App\Console\Commands\SimStartCommand;
use App\Models\SimRun;
use App\Services\AuditService;
use App\Services\Provision\ProvisionRunControl;
use App\Support\AutoscaleEnumeration;
use App\Support\HostCapacity;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * G2 — the sim resume enumeration walk, DB-free.
 *
 * The old walk paged by OFFSET and looped on rows INSERTED, so a resume whose
 * first page was already enrolled exited after one page and never reached the
 * later cohorts. The keyset walk loops on rows SCANNED and persists a durable
 * cursor per chunk. Fixture per docs/audits/2026-09-12/SETUP_AND_SCENARIO_AUDIT.md
 * line 56: pre-enroll the first page, leave later pages absent, resume twice,
 * prove complete enrollment without duplicates.
 *
 * The enumeration SQL is portable (the id generator and the cursor-id cast are
 * the only driver tokens), so this runs on a named sqlite fixture with a tiny
 * synthetic roster and a CGA_ENUM_CHUNK of 2 to force multi-chunk paging.
 */
final class SimResumeEnumerationTest extends TestCase
{
    private const CONNECTION = 'sim_resume_fixture';

    private string $originalConnection;

    /** @var array{CGA_ENUM_CHUNK: mixed} */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection(self::CONNECTION);
        self::assertSame('sqlite', DB::connection()->getDriverName());

        $schema = DB::connection()->getSchemaBuilder();

        $schema->create('jurisdictions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->integer('adm_level')->default(0);
            $t->integer('population')->nullable();
            $t->uuid('parent_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });

        $schema->create('sim_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('status')->default('queued');
            $t->string('phase')->default('cohorts');
            $t->text('options')->nullable();
            $t->text('phase_timings')->nullable();
            $t->text('enum_cursor')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamps();
        });

        $schema->create('sim_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('run_id');
            $t->string('kind');
            $t->string('status')->default('pending');
            $t->uuid('jurisdiction_id')->nullable();
            $t->integer('adm_level')->nullable();
            $t->string('unit_key');
            $t->integer('position')->default(0);
            $t->bigInteger('est_cost')->default(0);
            $t->text('metrics')->nullable();
            $t->timestamps();
            $t->unique(['run_id', 'kind', 'unit_key'], 'sim_items_unit_uq');
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::CONNECTION);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->originalConnection);
        $this->restoreEnv();
        parent::tearDown();
    }

    // ---- pure seam: stored options win over CLI on resume ------------------

    public function test_stored_options_win_over_cli_on_resume(): void
    {
        $r = SimStartCommand::resolveResumeParams(['adm_max' => 3, 'limit' => 10], 6, null);
        self::assertSame(3, $r['adm_max']);
        self::assertSame(10, $r['limit']);
        self::assertTrue($r['overrode']);

        // A limit stored as null wins over a command-line limit.
        $r2 = SimStartCommand::resolveResumeParams(['adm_max' => 6, 'limit' => null], 6, 500);
        self::assertSame(6, $r2['adm_max']);
        self::assertNull($r2['limit']);
        self::assertTrue($r2['overrode']);

        // Matching values do not report an override (the create path).
        $r3 = SimStartCommand::resolveResumeParams(['adm_max' => 6, 'limit' => null], 6, null);
        self::assertFalse($r3['overrode']);
    }

    // ---- pure seam: single-run selection on resume -------------------------

    public function test_named_run_wins_when_it_is_an_unfinished_candidate(): void
    {
        $candidates = [
            ['id' => 'b', 'created_at' => '2026-09-01 00:00:00'],
            ['id' => 'a', 'created_at' => '2026-09-02 00:00:00'],
        ];
        $sel = SimStartCommand::selectResumeRun($candidates, 'a');
        self::assertSame('a', $sel['run_id']);
        self::assertNull($sel['error']);
    }

    public function test_named_run_absent_from_the_candidate_set_refuses(): void
    {
        $sel = SimStartCommand::selectResumeRun(
            [['id' => 'a', 'created_at' => '2026-09-02 00:00:00']],
            'zzz'
        );
        self::assertNull($sel['run_id']);
        self::assertStringContainsString('zzz', (string) $sel['error']);
        self::assertStringContainsString('unfinished run', (string) $sel['error']);
    }

    public function test_plain_resume_takes_the_single_active_run(): void
    {
        $sel = SimStartCommand::selectResumeRun(
            [['id' => 'only', 'created_at' => '2026-09-05 00:00:00']],
            null
        );
        self::assertSame('only', $sel['run_id']);
        self::assertNull($sel['error']);
    }

    public function test_plain_resume_takes_the_oldest_never_the_newest(): void
    {
        // Deliberately unsorted; the newest is first in the list.
        $candidates = [
            ['id' => 'new', 'created_at' => '2026-09-10 00:00:00'],
            ['id' => 'old', 'created_at' => '2026-09-01 00:00:00'],
            ['id' => 'mid', 'created_at' => '2026-09-05 00:00:00'],
        ];
        $sel = SimStartCommand::selectResumeRun($candidates, null);
        self::assertSame('old', $sel['run_id'], 'the oldest is the single active run the pump keeps');
        self::assertNull($sel['error']);
    }

    public function test_equal_created_at_breaks_the_tie_by_lowest_id(): void
    {
        $candidates = [
            ['id' => 'z', 'created_at' => '2026-09-01 00:00:00'],
            ['id' => 'a', 'created_at' => '2026-09-01 00:00:00'],
        ];
        $sel = SimStartCommand::selectResumeRun($candidates, null);
        self::assertSame('a', $sel['run_id']);
    }

    public function test_empty_candidate_set_with_no_name_starts_fresh(): void
    {
        $sel = SimStartCommand::selectResumeRun([], null);
        self::assertNull($sel['run_id'], 'null run_id and null error tells the caller to start fresh');
        self::assertNull($sel['error']);
    }

    public function test_run_selection_over_real_sim_run_rows_on_the_fixture(): void
    {
        // Three unfinished runs plus one finished — the finished one is never a
        // candidate. Seed the same way the command queries them.
        $this->makeRun('r-old', 'running', '2026-09-01 00:00:00');
        $this->makeRun('r-mid', 'halted', '2026-09-05 00:00:00');
        $this->makeRun('r-new', 'queued', '2026-09-10 00:00:00');
        $this->makeRun('r-done', 'completed', '2026-09-02 00:00:00');

        $candidates = DB::table('sim_runs')
            ->whereIn('status', ['queued', 'running', 'halted'])
            ->orderBy('created_at')
            ->get(['id', 'created_at'])
            ->map(fn ($r) => ['id' => (string) $r->id, 'created_at' => (string) $r->created_at])
            ->all();

        self::assertCount(3, $candidates, 'the finished run is excluded');

        // Plain resume adopts the oldest unfinished run.
        self::assertSame('r-old', SimStartCommand::selectResumeRun($candidates, null)['run_id']);

        // --run names a specific unfinished run, even a newer one.
        self::assertSame('r-new', SimStartCommand::selectResumeRun($candidates, 'r-new')['run_id']);

        // Naming the finished run refuses — it is not a candidate.
        $refuse = SimStartCommand::selectResumeRun($candidates, 'r-done');
        self::assertNull($refuse['run_id']);
        self::assertNotNull($refuse['error']);
    }

    // ---- chunk size derivation ---------------------------------------------

    public function test_enumeration_chunk_honours_env_override_and_floor(): void
    {
        $this->setEnv('CGA_ENUM_CHUNK', '7');
        self::assertSame(7, HostCapacity::enumerationChunk());

        // No override: the derived value never drops below the Pi floor.
        $this->setEnv('CGA_ENUM_CHUNK', null);
        self::assertGreaterThanOrEqual(1000, HostCapacity::enumerationChunk());
        self::assertLessThanOrEqual(100000, HostCapacity::enumerationChunk());
    }

    public function test_the_three_siblings_read_one_chunk_source(): void
    {
        $this->setEnv('CGA_ENUM_CHUNK', '4242');
        self::assertSame(4242, HostCapacity::enumerationChunk());
        self::assertSame(4242, SimStartCommand::chunk());
        self::assertSame(4242, ProvisionRunControl::ledgerChunk());
        self::assertSame(4242, AutoscaleEnumeration::chunk());
    }

    // ---- the keyset walk ----------------------------------------------------

    public function test_fresh_run_enrols_every_row_across_several_chunks(): void
    {
        $this->setEnv('CGA_ENUM_CHUNK', '2');
        $this->seedRoster([500, 400, 300, 200, 100]); // 5 rows, chunk 2 => 3 chunks

        $run = $this->newRun();
        $minted = $this->enumerate($run, 6, null);

        self::assertSame(5, $minted);
        self::assertSame(5, $this->itemCount($run));

        // Positions are dense 1..5, largest-population first.
        self::assertSame([1, 2, 3, 4, 5], $this->positions($run));
        self::assertSame([500, 400, 300, 200, 100], $this->estCostsByPosition($run));

        // The cursor is persisted at the tail of the walk.
        $run->refresh();
        self::assertIsArray($run->enum_cursor);
        self::assertSame(5, (int) $run->enum_cursor['scanned_total']);
        self::assertSame(5, (int) $run->enum_cursor['position_max']);
        self::assertSame(100, (int) $run->enum_cursor['key']);
    }

    public function test_resume_after_a_partial_insert_reaches_later_rows_without_duplicates_or_skips(): void
    {
        $this->setEnv('CGA_ENUM_CHUNK', '2');
        $ids = $this->seedRoster([500, 400, 300, 200, 100]);

        // A run whose FIRST page (the two largest) is already enrolled, but no
        // cursor was stored — the old restart-from-zero enumerator's residue.
        $run = $this->newRun();
        $this->preEnrol($run, [[$ids[0], 500, 1], [$ids[1], 400, 2]]);
        self::assertNull($run->enum_cursor);

        // Resume: recovery derives the boundary from the enrolled prefix and
        // continues past it.
        $minted = $this->enumerate($run, 6, null);

        self::assertSame(3, $minted, 'only the three later rows are newly minted');
        self::assertSame(5, $this->itemCount($run), 'all five rows are enrolled');
        self::assertSame([1, 2, 3, 4, 5], $this->positions($run), 'positions dense and monotonic across the boundary');
        self::assertSame([500, 400, 300, 200, 100], $this->estCostsByPosition($run));
        self::assertCount(5, $this->distinctUnitKeys($run), 'no duplicate unit_keys');

        // A second resume is a no-op: no skips, no duplicates.
        $again = $this->enumerate($run, 6, null);
        self::assertSame(0, $again);
        self::assertSame(5, $this->itemCount($run));
        self::assertCount(5, $this->distinctUnitKeys($run));

        // A third resume is still a no-op (repeat resumes are stable).
        self::assertSame(0, $this->enumerate($run, 6, null));
        self::assertSame(5, $this->itemCount($run));
    }

    public function test_resume_from_a_stored_cursor_continues_from_the_stored_maximum(): void
    {
        $this->setEnv('CGA_ENUM_CHUNK', '2');
        $ids = $this->seedRoster([500, 400, 300, 200, 100]);

        // Enrol the two largest, and store a cursor pointing at the second row.
        $run = $this->newRun();
        $this->preEnrol($run, [[$ids[0], 500, 1], [$ids[1], 400, 2]]);
        $run->forceFill(['enum_cursor' => [
            'key' => 400, 'id' => $ids[1], 'position_max' => 2, 'scanned_total' => 2,
        ]])->save();

        $minted = $this->enumerate($run, 6, null);

        self::assertSame(3, $minted);
        self::assertSame([1, 2, 3, 4, 5], $this->positions($run));
        self::assertSame([500, 400, 300, 200, 100], $this->estCostsByPosition($run));
    }

    // ---- helpers -----------------------------------------------------------

    /** @param list<int> $pops @return list<string> the created ids, in seed order */
    private function seedRoster(array $pops): array
    {
        $ids = [];
        $i = 0;
        foreach ($pops as $pop) {
            // Deterministic, ascending ids so the (population, id) order is fixed.
            $id = sprintf('00000000-0000-0000-0000-%012d', ++$i);
            $ids[] = $id;
            DB::table('jurisdictions')->insert([
                'id' => $id,
                'name' => 'J'.$i,
                'slug' => 'j'.$i,
                'adm_level' => 2,
                'population' => $pop,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    /** Insert a sim_runs row with an explicit id, status and created_at. */
    private function makeRun(string $id, string $status, string $createdAt): void
    {
        DB::table('sim_runs')->insert([
            'id' => $id,
            'status' => $status,
            'phase' => 'cohorts',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function newRun(): SimRun
    {
        return SimRun::create([
            'status' => 'queued',
            'phase' => 'cohorts',
            'options' => ['adm_max' => 6, 'limit' => null, 'scope_jurisdiction_id' => null],
            'phase_timings' => [],
        ]);
    }

    /** @param list<array{0:string,1:int,2:int}> $rows [id, population, position] */
    private function preEnrol(SimRun $run, array $rows): void
    {
        foreach ($rows as [$id, $pop, $pos]) {
            DB::table('sim_items')->insert([
                'id' => sprintf('11111111-0000-0000-0000-%012d', $pos),
                'run_id' => $run->id,
                'kind' => 'cohort_scope',
                'status' => 'pending',
                'jurisdiction_id' => $id,
                'adm_level' => 2,
                'unit_key' => $id,
                'position' => $pos,
                'est_cost' => $pop,
                'metrics' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $run->refresh();
    }

    private function enumerate(SimRun $run, int $admMax, ?int $limit): int
    {
        $command = new SimStartCommand($this->createMock(AuditService::class));
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

        $method = new ReflectionMethod(SimStartCommand::class, 'enumerateCohorts');
        $method->setAccessible(true);

        return (int) $method->invoke($command, $run, $admMax, $limit, null);
    }

    private function itemCount(SimRun $run): int
    {
        return (int) DB::table('sim_items')->where('run_id', $run->id)->where('kind', 'cohort_scope')->count();
    }

    /** @return list<int> */
    private function positions(SimRun $run): array
    {
        return DB::table('sim_items')
            ->where('run_id', $run->id)->where('kind', 'cohort_scope')
            ->orderBy('position')->pluck('position')->map(fn ($v) => (int) $v)->all();
    }

    /** @return list<int> est_cost ordered by position asc */
    private function estCostsByPosition(SimRun $run): array
    {
        return DB::table('sim_items')
            ->where('run_id', $run->id)->where('kind', 'cohort_scope')
            ->orderBy('position')->pluck('est_cost')->map(fn ($v) => (int) $v)->all();
    }

    /** @return list<string> */
    private function distinctUnitKeys(SimRun $run): array
    {
        return DB::table('sim_items')
            ->where('run_id', $run->id)->where('kind', 'cohort_scope')
            ->distinct()->pluck('unit_key')->all();
    }

    private function setEnv(string $key, ?string $value): void
    {
        if (! array_key_exists($key, $this->savedEnv)) {
            $this->savedEnv[$key] = getenv($key);
        }
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function restoreEnv(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $this->savedEnv = [];
    }
}
