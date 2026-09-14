<?php

namespace Tests\Unit;

use App\Jobs\EvaluateSocialStructureJob;
use App\Models\SocialSpace;
use App\Services\Matrix\SocialTopologyReconcilerService;
use App\Services\Social\SubforumReconciler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * W-0165 — the halls-binding sweep is a bounded, resumable keyset walk.
 *
 * A NAMED sqlite fixture (never the live world). The SubforumReconciler and the
 * Matrix topology reconciler are stubbed: their behaviour has its own PG pin
 * (SubforumReconcilerTest). This test pins the JOB'S walk: distinct active
 * legislatures, ordered by jurisdiction id, in bounded chunks; the cursor
 * commits per chunk; a kill mid-run resumes from the committed cursor without
 * re-binding a bound jurisdiction or skipping an unbound one; a completed run
 * clears the cursor; and the spaces compose for every active jurisdiction.
 */
final class SocialSweepChunkingTest extends TestCase
{
    private const CURSOR_KEY = 'social:evaluate:sweep_cursor';

    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.social_sweep_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::setDefaultConnection('social_sweep_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        Cache::forget(self::CURSOR_KEY);

        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('legislatures', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('jurisdiction_id');
            $t->string('status')->default('forming');
            $t->timestamps();
            $t->softDeletes();
        });
        $schema->create('social_spaces', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('jurisdiction_id');
            $t->string('space_type');
            $t->string('title')->nullable();
            $t->string('slug')->nullable();
            $t->string('status')->nullable();
            $t->boolean('is_private')->default(false);
            $t->uuid('owner_org_id')->nullable();
            $t->uuid('owner_user_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Cache::forget(self::CURSOR_KEY);
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    /** A deterministic, ascending jurisdiction id. */
    private function jur(int $n): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $n);
    }

    private function seedLegislature(string $jurisdictionId, string $status): void
    {
        DB::table('legislatures')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'jurisdiction_id' => $jurisdictionId,
            'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A spy reconciler that records each jurisdiction it binds and can throw to simulate a kill. */
    private function spyReconciler(int $killAt = PHP_INT_MAX): SubforumReconciler
    {
        return new class($killAt) extends SubforumReconciler
        {
            /** @var array<int,string> */
            public array $bound = [];

            public function __construct(private int $killAt) {}

            public function gatherLiveObjects(string $jurisdictionId): array
            {
                if (count($this->bound) === $this->killAt) {
                    throw new \RuntimeException('killed mid-sweep');
                }
                $this->bound[] = $jurisdictionId;

                return [];
            }

            public function reconcile(SocialSpace $hallsSpace, array $liveObjects): array
            {
                return ['created' => 0, 'reopened' => 0, 'archived' => 0];
            }
        };
    }

    private function stubTopology(): SocialTopologyReconcilerService
    {
        return new class extends SocialTopologyReconcilerService
        {
            public function __construct() {}

            public function reconcileJurisdiction(string $jurisdictionId, bool $isSeated, bool $isActivated = true): void {}
        };
    }

    private function job(int $chunkSize): EvaluateSocialStructureJob
    {
        return new class(null, $chunkSize) extends EvaluateSocialStructureJob
        {
            public function __construct(?string $jurisdictionId, private int $size)
            {
                parent::__construct($jurisdictionId);
            }

            protected function chunkSize(): int
            {
                return $this->size;
            }
        };
    }

    public function test_sweep_walks_distinct_active_legislatures_in_keyset_chunks_and_composes_spaces(): void
    {
        // Five distinct active jurisdictions, one inactive (must be skipped),
        // and a second active chamber sharing jurisdiction 1 (must not double it).
        for ($i = 1; $i <= 5; $i++) {
            $this->seedLegislature($this->jur($i), 'active');
        }
        $this->seedLegislature($this->jur(6), 'forming');
        $this->seedLegislature($this->jur(1), 'active'); // a bicameral second chamber, same jurisdiction

        $spy = $this->spyReconciler();
        $this->job(2)->handle($spy, $this->stubTopology());

        self::assertSame(
            [$this->jur(1), $this->jur(2), $this->jur(3), $this->jur(4), $this->jur(5)],
            $spy->bound,
            'each active jurisdiction is bound exactly once, in ascending id order, and the inactive one is skipped'
        );
        self::assertSame(5, SocialSpace::query()->where('space_type', 'public_square')->count());
        self::assertSame(5, SocialSpace::query()->where('space_type', 'halls')->count());
        self::assertNull(Cache::get(self::CURSOR_KEY), 'a completed sweep clears its cursor');
    }

    public function test_a_kill_after_the_first_chunk_resumes_without_rebinding_or_skipping(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seedLegislature($this->jur($i), 'active');
        }

        // Kill on the first bind of the SECOND chunk (after two are bound and
        // the first chunk's cursor is committed).
        $killed = $this->spyReconciler(killAt: 2);
        try {
            $this->job(2)->handle($killed, $this->stubTopology());
            self::fail('the spy must throw to simulate a kill');
        } catch (\RuntimeException $e) {
            self::assertSame('killed mid-sweep', $e->getMessage());
        }

        self::assertSame([$this->jur(1), $this->jur(2)], $killed->bound, 'only the first chunk was bound');
        self::assertSame($this->jur(2), Cache::get(self::CURSOR_KEY), 'the cursor sits at the first chunk boundary');

        // Resume: a fresh run reads the committed cursor.
        $resume = $this->spyReconciler();
        $this->job(2)->handle($resume, $this->stubTopology());

        self::assertSame(
            [$this->jur(3), $this->jur(4), $this->jur(5)],
            $resume->bound,
            'resume binds only the jurisdictions past the cursor — no re-binding, no skipping'
        );
        self::assertSame(
            [],
            array_intersect($killed->bound, $resume->bound),
            'no jurisdiction is bound in both runs'
        );
        self::assertNull(Cache::get(self::CURSOR_KEY), 'the resumed run runs to the end and clears the cursor');
        self::assertSame(5, SocialSpace::query()->where('space_type', 'halls')->count());
    }

    public function test_chunk_size_derives_from_the_host(): void
    {
        // The production default is the host-derived enumeration chunk, env-overridable.
        $real = new EvaluateSocialStructureJob;
        $method = new \ReflectionMethod($real, 'chunkSize');
        $method->setAccessible(true);
        self::assertSame(\App\Support\HostCapacity::enumerationChunk(), $method->invoke($real));
    }
}
