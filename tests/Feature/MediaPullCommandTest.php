<?php

namespace Tests\Feature;

use App\Jobs\Media\PullMediaItemJob;
use App\Models\MediaPull;
use App\Models\MediaPullItem;
use App\Services\Media\MediaPullPlanner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W-0448 — media:pull resumes a halted run from the console (WoS 2026-09-17:
 * the CLI refused a halted run because activePull() counts halted as active,
 * so only the wizard could continue one). Runs on the phpunit sqlite :memory:
 * fixture; the media tables are built by the real migration's up(); the queue
 * is faked so no transfer runs. No RefreshDatabase (forbidden).
 */
class MediaPullCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require base_path('database/migrations/2026_09_16_180000_media_library_tables.php'))->up();
        Queue::fake();
    }

    private function haltedRun(int $pending = 2, int $failed = 0): MediaPull
    {
        $pull = new MediaPull;
        $pull->id = (string) Str::uuid();
        $pull->source = 'web';
        $pull->source_ref = 'https://example.test/uploads';
        $pull->status = 'halted';
        $pull->options = [];
        $pull->items_total = $pending + $failed;
        $pull->items_failed = $failed;
        $pull->started_at = now();
        $pull->save();

        $i = 0;
        foreach (array_merge(array_fill(0, $pending, 'pending'), array_fill(0, $failed, 'failed')) as $status) {
            $i++;
            MediaPullItem::query()->create([
                'id'         => (string) Str::uuid(),
                'pull_id'    => $pull->id,
                'subject'    => 'Film '.$i,
                'kind'       => 'captions',
                'track_name' => 'English',
                'source'     => "Subjects/Film {$i}/captions/Film {$i}-English.vtt",
                'dest'       => "Film {$i}/captions/Film {$i}-English.vtt",
                'status'     => $status,
                'error'      => $status === 'failed' ? 'HTTP 503' : null,
            ]);
        }

        return $pull;
    }

    public function test_a_new_pull_is_refused_while_a_run_is_halted_and_names_the_way_to_continue(): void
    {
        $this->haltedRun();

        $code = Artisan::call('media:pull');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--resume', Artisan::output());
        Queue::assertNothingPushed();
    }

    public function test_resume_flips_the_halted_run_to_running_and_dispatches_its_pending_items(): void
    {
        $pull = $this->haltedRun(pending: 2);

        $code = Artisan::call('media:pull', ['--resume' => true]);

        $this->assertSame(0, $code);
        $this->assertSame('running', $pull->fresh()->status);
        Queue::assertPushed(PullMediaItemJob::class, 2);
    }

    public function test_resume_with_nothing_halted_is_a_no_op(): void
    {
        $code = Artisan::call('media:pull', ['--resume' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No halted media pull', Artisan::output());
        Queue::assertNothingPushed();
    }

    public function test_retry_failed_resets_failed_items_and_dispatches_them(): void
    {
        $pull = $this->haltedRun(pending: 0, failed: 3);
        $pull->forceFill(['status' => 'failed'])->save();

        $code = Artisan::call('media:pull', ['--retry-failed' => true]);

        $this->assertSame(0, $code);
        $fresh = $pull->fresh();
        $this->assertSame('running', $fresh->status);
        $this->assertSame(0, (int) $fresh->items_failed);
        $this->assertSame(3, MediaPullItem::query()->where('pull_id', $pull->id)->where('status', 'pending')->count());
        Queue::assertPushed(PullMediaItemJob::class, 3);
    }

    public function test_the_planner_resume_is_the_single_owner_the_wizard_shares(): void
    {
        $pull = $this->haltedRun(pending: 1);

        $n = app(MediaPullPlanner::class)->resume($pull);

        $this->assertSame(1, $n);
        $this->assertSame('running', $pull->fresh()->status);
        $this->assertSame(0, app(MediaPullPlanner::class)->resume($pull->fresh()), 'a running run is not resumed twice');
    }
}
