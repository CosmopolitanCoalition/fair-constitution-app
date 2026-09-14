<?php

namespace Tests\Constitutional;

use App\Jobs\ActivateSubtreeJob;
use App\Jobs\FinishActivationsJob;
use App\Jobs\SubtreeBootLaneJob;
use App\Services\SubtreeBootService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PIN — W-0253 subtree-boot recovery.
 *
 * The multi-lane subtree boot had three defects. This pins the fixes in
 * SubtreeBootService and the two coordinator jobs:
 *
 *  1. A three-strike running row no longer pins the pile. claim() excludes
 *     rows at MAX_ATTEMPTS from candidacy, and reclaimStuck() moves a stale
 *     maxed-out running row to review so a lane never waits on it.
 *  2. Lanes do not collapse after the root wave. Readiness is per-parent,
 *     not one global shallowest depth, so every ready sibling is claimable
 *     at once. A child is claimable only after its parent row is terminal.
 *  3. The reset control SEIZES: it clears the pile and the progress cache
 *     regardless of any running lane.
 *
 * Plus the queue and mode routing: the coordinator and the bulk finish run
 * off the default queue, and the finish honours the scale mode.
 *
 * A NAMED sqlite fixture (never the live world). If an edit breaks these,
 * the edit is the violation — fix the edit.
 */
class SubtreeBootRecoveryTest extends TestCase
{
    private string $original;

    private SubtreeBootService $pile;

    private const ROOT = '10000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.subtree_boot_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::setDefaultConnection('subtree_boot_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());

        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jurisdictions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('parent_id')->nullable();
            $t->string('slug');
            $t->string('name')->nullable();
            $t->integer('adm_level')->default(0);
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('subtree_boot_items', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('root_id');
            $t->string('jurisdiction_id');
            $t->string('slug');
            $t->integer('depth');
            $t->string('status')->default('pending');
            $t->string('claim_token')->nullable();
            $t->text('reason')->nullable();
            $t->integer('attempts')->default(0);
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });

        $this->pile = new SubtreeBootService();
    }

    protected function tearDown(): void
    {
        Cache::forget(ActivateSubtreeJob::progressKey(self::ROOT));
        DB::purge('subtree_boot_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function jur(string $id, ?string $parent, int $adm): void
    {
        DB::table('jurisdictions')->insert([
            'id' => $id, 'parent_id' => $parent, 'slug' => 'j-'.substr($id, -4),
            'name' => 'J'.substr($id, -4), 'adm_level' => $adm,
        ]);
    }

    private function item(string $jid, int $depth, string $status = 'pending', int $attempts = 0, ?int $updatedMinutesAgo = null): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('subtree_boot_items')->insert([
            'id' => $id, 'root_id' => self::ROOT, 'jurisdiction_id' => $jid,
            'slug' => 'j-'.substr($jid, -4), 'depth' => $depth,
            'status' => $status, 'attempts' => $attempts,
            'created_at' => now(), 'updated_at' => $updatedMinutesAgo === null ? now() : now()->subMinutes($updatedMinutesAgo),
        ]);

        return $id;
    }

    public function test_claim_excludes_a_row_at_max_attempts(): void
    {
        $this->jur(self::ROOT, null, 0);
        // A pending row that has already used all its attempts is not claimable.
        $this->item(self::ROOT, 0, 'pending', SubtreeBootService::MAX_ATTEMPTS);

        $this->assertNull($this->pile->claim(self::ROOT), 'a row at MAX_ATTEMPTS is excluded from candidacy');
    }

    public function test_a_stuck_maxed_running_row_is_reclaimed_to_review_and_never_pins_the_pile(): void
    {
        $child = '10000000-0000-4000-8000-000000000010';
        $this->jur(self::ROOT, null, 0);
        $this->jur($child, self::ROOT, 1);

        // Root already booted.
        $this->item(self::ROOT, 0, 'done');
        // A dead lane left this child running at MAX_ATTEMPTS, stale.
        $stuck = $this->item($child, 1, 'running', SubtreeBootService::MAX_ATTEMPTS, updatedMinutesAgo: SubtreeBootService::STALE_MINUTES + 5);

        // reclaimStuck fires inside claim(): the stuck row is terminalled.
        $claim = $this->pile->claim(self::ROOT);

        $this->assertSame('review', DB::table('subtree_boot_items')->where('id', $stuck)->value('status'),
            'a stale three-strike running row is moved to review, not left running');
        $this->assertNull($claim, 'nothing else is claimable, so the pile drains instead of pinning');
        $this->assertFalse($this->pile->hasOpenWork(self::ROOT), 'the pile has no open work once the stuck row is reviewed');
    }

    public function test_lanes_do_not_collapse_ready_siblings_open_together(): void
    {
        $childA = '10000000-0000-4000-8000-00000000001a';
        $childB = '10000000-0000-4000-8000-00000000001b';
        $this->jur(self::ROOT, null, 0);
        $this->jur($childA, self::ROOT, 1);
        $this->jur($childB, self::ROOT, 1);

        $rootItem = $this->item(self::ROOT, 0, 'pending');
        $this->item($childA, 1, 'pending');
        $this->item($childB, 1, 'pending');

        // While the root is pending, only the root is claimable — a child
        // never boots before its parent.
        $first = $this->pile->claim(self::ROOT);
        $this->assertNotNull($first);
        $this->assertSame(self::ROOT, $first->jurisdiction_id, 'the root claims before any child');

        // A second lane finds nothing while the root is still running.
        $this->assertNull($this->pile->claim(self::ROOT), 'a child is blocked while its parent is running');

        // Root done: BOTH children are now claimable in the same wave.
        $this->pile->finalize($rootItem, $first->claim_token, 'done');

        $claimA = $this->pile->claim(self::ROOT);
        $claimB = $this->pile->claim(self::ROOT);
        $this->assertNotNull($claimA);
        $this->assertNotNull($claimB);
        $ids = [$claimA->jurisdiction_id, $claimB->jurisdiction_id];
        sort($ids);
        $this->assertSame([$childA, $childB], $ids, 'both ready siblings open at once — lanes do not collapse to one');
    }

    public function test_reset_seizes_the_pile_and_clears_progress_even_with_a_running_row(): void
    {
        $this->jur(self::ROOT, null, 0);
        $this->item(self::ROOT, 0, 'running', 1);
        $this->item(self::ROOT.'x', 1, 'pending');
        Cache::put(ActivateSubtreeJob::progressKey(self::ROOT), ['total' => 2], 7200);

        $removed = $this->pile->reset(self::ROOT);

        $this->assertSame(2, $removed, 'reset deletes the whole pile regardless of a running row');
        $this->assertSame(0, DB::table('subtree_boot_items')->where('root_id', self::ROOT)->count());
        $this->assertNull(Cache::get(ActivateSubtreeJob::progressKey(self::ROOT)), 'reset clears the progress cache');
    }

    // ── Queue and mode routing (source-level pins) ──────────────────────────

    public function test_the_coordinator_jobs_run_off_the_default_queue(): void
    {
        $activate = new ActivateSubtreeJob(self::ROOT);
        $this->assertSame('long-running', $activate->queue, 'the subtree coordinator must not sit on the default queue');

        $finish = new FinishActivationsJob();
        $this->assertSame('long-running', $finish->queue, 'the bulk finish must not sit on the default queue');

        $lane = new SubtreeBootLaneJob(self::ROOT, 0, false);
        $this->assertSame('autoscale', $lane->queue, 'a boot lane runs on the autoscale pool');
    }

    public function test_finish_activations_honours_the_scale_mode(): void
    {
        // A source pin: the bulk finish reads institution_scale_mode and only
        // schedules a founding election in population mode.
        $src = (string) file_get_contents(base_path('app/Jobs/FinishActivationsJob.php'));
        $this->assertStringContainsString('institution_scale_mode', $src);
        $this->assertStringContainsString("!== 'population'", $src);
        $this->assertStringContainsString("'--no-election' => \$skipElections", $src);
    }
}
