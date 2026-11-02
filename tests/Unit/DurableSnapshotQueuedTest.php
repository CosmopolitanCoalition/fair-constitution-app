<?php

namespace Tests\Unit;

use App\Jobs\RefreshDurableSnapshotJob;
use App\Support\DurableSnapshot;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DurableSnapshot::rememberQueued never builds in the request. DB-free: the cold path needs no
 * stored row, the queue is faked, and an unreachable table reads as "nothing stored".
 */
class DurableSnapshotQueuedTest extends TestCase
{
    public function test_a_cold_read_returns_the_placeholder_and_queues_one_build(): void
    {
        Queue::fake();

        $value = DurableSnapshot::rememberQueued('test.queued.cold', 60, \stdClass::class, 'noSuchMethod', ['placeholder' => true]);

        $this->assertSame(['placeholder' => true], $value);
        Queue::assertPushed(RefreshDurableSnapshotJob::class, fn ($job) => $job->key === 'test.queued.cold' && $job->queue === 'prewarm');
    }

    public function test_the_three_admin_pages_use_the_queued_form(): void
    {
        foreach ([
            'Http/Controllers/System/ClocksController.php' => "rememberQueued('clocks.armed'",
            'Http/Controllers/LegislatureController.php' => "rememberQueued('legislatures.hub.rows'",
            'Http/Controllers/BuildProgressController.php' => "rememberQueued('build.progress'",
        ] as $file => $needle) {
            $this->assertStringContainsString($needle, file_get_contents(app_path($file)));
        }
    }
}
