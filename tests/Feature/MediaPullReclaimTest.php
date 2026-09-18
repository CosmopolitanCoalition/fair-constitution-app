<?php

namespace Tests\Feature;

use App\Jobs\Media\PullMediaItemJob;
use App\Models\MediaPull;
use App\Models\MediaPullItem;
use App\Services\Media\MediaPullPlanner;
use App\Services\Media\MediaTransfer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W-0448 — the stale-claim reclaim (operator order 2026-09-18). WoS demo box
 * run c2c584c0: the kernel killed seven lanes mid-master; each item stayed
 * `running` with no worker, the run never closed, --resume found nothing
 * pending, --retry-failed found nothing failed and a new run was refused.
 *
 * Runs on the phpunit sqlite :memory: fixture; the media tables are built by
 * the real migration's up(); the queue is faked so no transfer runs. No
 * RefreshDatabase (forbidden).
 */
class MediaPullReclaimTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require base_path('database/migrations/2026_09_16_180000_media_library_tables.php'))->up();
        Queue::fake();
    }

    /**
     * A run with $stale running items last touched past the window, $live
     * running items touched now, and $pending pending items.
     */
    private function makeRun(string $status, int $stale = 0, int $live = 0, int $pending = 0, int $failed = 0): MediaPull
    {
        $pull = new MediaPull;
        $pull->id = (string) Str::uuid();
        $pull->source = 'web';
        $pull->source_ref = 'https://example.test/uploads';
        $pull->status = $status;
        $pull->options = [];
        $pull->items_total = $stale + $live + $pending + $failed;
        $pull->items_failed = $failed;
        $pull->started_at = now();
        $pull->save();

        $old = now()->subSeconds(MediaTransfer::staleAfterSeconds() + 60);
        $i = 0;
        $make = function (string $itemStatus, $touchedAt, int $bytes = 0) use ($pull, &$i): void {
            $i++;
            $id = (string) Str::uuid();
            MediaPullItem::query()->create([
                'id'         => $id,
                'pull_id'    => $pull->id,
                'subject'    => 'Film '.$i,
                'kind'       => 'master',
                'track_name' => null,
                'source'     => "Subjects/Film {$i}/Film {$i}-Silent.mp4",
                'dest'       => "Film {$i}/Film {$i}-Silent.mp4",
                'status'     => $itemStatus,
                'bytes_done' => $bytes,
                'attempts'   => $itemStatus === 'pending' ? 0 : 1,
                'error'      => $itemStatus === 'failed' ? 'HTTP 503' : null,
            ]);
            // The model stamps updated_at = now; the fixture sets the age directly.
            DB::table('media_pull_items')->where('id', $id)->update(['updated_at' => $touchedAt]);
        };
        for ($n = 0; $n < $stale; $n++) {
            $make('running', $old, 1234);
        }
        for ($n = 0; $n < $live; $n++) {
            $make('running', now());
        }
        for ($n = 0; $n < $pending; $n++) {
            $make('pending', now());
        }
        for ($n = 0; $n < $failed; $n++) {
            $make('failed', now());
        }

        return $pull;
    }

    private function itemCount(MediaPull $pull, string $status): int
    {
        return MediaPullItem::query()->where('pull_id', $pull->id)->where('status', $status)->count();
    }

    public function test_the_window_derives_from_the_transfer_bounds(): void
    {
        $this->assertSame(
            MediaTransfer::MAX_NO_PROGRESS * (MediaTransfer::CONNECT_TIMEOUT + MediaTransfer::READ_TIMEOUT) + MediaTransfer::READ_TIMEOUT,
            MediaTransfer::staleAfterSeconds()
        );
    }

    public function test_a_stale_running_item_returns_to_the_pool_and_a_live_one_is_kept(): void
    {
        $pull = $this->makeRun('running', stale: 2, live: 1);
        $planner = app(MediaPullPlanner::class);

        $this->assertSame(2, $planner->staleCount($pull));
        $ids = $planner->reclaimStale($pull);

        $this->assertCount(2, $ids);
        $this->assertSame(2, $this->itemCount($pull, 'pending'));
        $this->assertSame(1, $this->itemCount($pull, 'running'), 'a lane that touched its row inside the window is alive');
        $this->assertSame(0, $planner->staleCount($pull));
        $this->assertSame(
            2,
            MediaPullItem::query()->whereIn('id', $ids)->where('bytes_done', 1234)->count(),
            'bytes_done is kept: the .part resumes by Range'
        );
    }

    public function test_force_returns_every_running_item(): void
    {
        $pull = $this->makeRun('running', stale: 1, live: 2);

        $this->assertCount(3, app(MediaPullPlanner::class)->reclaimStale($pull, force: true));
        $this->assertSame(0, $this->itemCount($pull, 'running'));
    }

    public function test_resume_on_a_running_run_dispatches_only_the_reclaimed_items(): void
    {
        // The demo-box state: nothing pending, nothing failed, stale claims only.
        $pull = $this->makeRun('running', stale: 7, pending: 3);

        $code = Artisan::call('media:pull', ['--resume' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('7 stalled item(s) returned to the pool', Artisan::output());
        $this->assertSame('running', $pull->fresh()->status);
        $this->assertSame(10, $this->itemCount($pull, 'pending'));
        Queue::assertPushed(PullMediaItemJob::class, 7);
    }

    public function test_resume_on_a_running_run_with_live_lanes_only_changes_nothing(): void
    {
        $pull = $this->makeRun('running', live: 2, pending: 1);

        $code = Artisan::call('media:pull', ['--resume' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('no stalled item', Artisan::output());
        $this->assertSame(2, $this->itemCount($pull, 'running'));
        Queue::assertNothingPushed();
    }

    public function test_requeue_running_seizes_live_claims_too(): void
    {
        $pull = $this->makeRun('running', live: 2);

        $code = Artisan::call('media:pull', ['--requeue-running' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(0, $this->itemCount($pull, 'running'));
        Queue::assertPushed(PullMediaItemJob::class, 2);
    }

    public function test_resume_on_a_halted_run_reclaims_then_dispatches_every_pending_item(): void
    {
        $pull = $this->makeRun('halted', stale: 2, pending: 3);

        $n = app(MediaPullPlanner::class)->resume($pull);

        $this->assertSame(5, $n);
        $this->assertSame('running', $pull->fresh()->status);
        Queue::assertPushed(PullMediaItemJob::class, 5);
    }

    public function test_retry_failed_heals_stale_claims_as_well(): void
    {
        $pull = $this->makeRun('running', stale: 1, failed: 2);

        [$reset, $dispatched] = app(MediaPullPlanner::class)->retryFailed($pull);

        $this->assertSame(2, $reset);
        $this->assertSame(3, $dispatched);
        $this->assertSame(0, $this->itemCount($pull, 'running'));
    }

    public function test_settle_heals_a_dead_sibling_and_keeps_the_run_open(): void
    {
        $pull = $this->makeRun('running', stale: 1);

        app(MediaPullPlanner::class)->settle($pull->id);

        $this->assertSame('running', $pull->fresh()->status, 'the reclaimed item is pending, so the run is not drained');
        $this->assertSame(1, $this->itemCount($pull, 'pending'));
        Queue::assertPushed(PullMediaItemJob::class, 1);
    }

    public function test_settle_closes_a_drained_run(): void
    {
        $pull = $this->makeRun('running');

        app(MediaPullPlanner::class)->settle($pull->id);

        $this->assertSame('done', $pull->fresh()->status);
    }

    public function test_status_names_the_stalled_items(): void
    {
        $this->makeRun('running', stale: 3, live: 1);

        Artisan::call('media:pull', ['--status' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('Running: 4 item(s)', $out);
        $this->assertStringContainsString('STALLED: 3 running item(s)', $out);
        $this->assertStringContainsString('--resume', $out);
    }
}
