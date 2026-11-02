<?php

namespace App\Jobs;

use App\Support\DurableSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use ReflectionMethod;

/**
 * Builds one stored page figure (DurableSnapshot) in the background.
 *
 * Some admin pages read a figure that takes minutes to compute on a slow disk. A page never
 * waits for it: a cold read answers with a placeholder and dispatches this job, which calls the
 * named builder and stores the result. One job per key at a time, on the low-priority prewarm lane.
 */
class RefreshDurableSnapshotJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    /** The unique lock outlives a normal build and clears itself if the job dies. */
    public int $uniqueFor = 3600;

    /** @param class-string $builderClass a class whose container-built instance has the builder method */
    public function __construct(
        public readonly string $key,
        public readonly string $builderClass,
        public readonly string $builderMethod,
    ) {
        $this->onQueue('prewarm');
    }

    public function uniqueId(): string
    {
        return $this->key;
    }

    public function handle(): void
    {
        $method = new ReflectionMethod($this->builderClass, $this->builderMethod);
        $method->setAccessible(true);

        DurableSnapshot::store($this->key, $method->invoke(app($this->builderClass)));
    }
}
