<?php

namespace App\Jobs;

use App\Services\SubtreeBootService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * ONE LANE of the multi-lane subtree boot (operator ruling 2026-08-29, A4;
 * lane-collapse + reclaim fix W-0253).
 *
 * Claims ONE ready node from the root's pile through SubtreeBootService,
 * runs the same per-node boot the serial walk ran (seed if the chamber is
 * missing, then WF-JUR-01 activation, founding elections only where the
 * mode says voters exist), records the outcome, publishes progress, and
 * dispatches its own replacement while any work remains.
 *
 * LANES DO NOT COLLAPSE (the multi-lane paradigm). Readiness is per-parent,
 * so every ready sibling opens at once and a wave keeps its lanes. A lane
 * that finds nothing to claim but sees open work stays alive by
 * re-dispatching itself with a short backoff, so the pool keeps its width
 * across waves; it retires only when the pile is drained. THE SMALLS NEVER
 * STOP. A lane death costs one node: a stale claim is reclaimed after
 * STALE_MINUTES, a three-strike running row lands in review.
 */
class SubtreeBootLaneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;
    public int $tries = 1;   // retries are the pile's job

    /** Seconds an empty-but-blocked lane waits before it tries again. */
    private const EMPTY_BACKOFF_SECONDS = 5;

    public function __construct(
        public string $rootId,
        public int $lane,
        public bool $skipElections,
    ) {
        $this->onQueue('autoscale');
    }

    public function handle(SubtreeBootService $pile): void
    {
        $row = $pile->claim($this->rootId);

        if ($row === null) {
            $pile->publishProgress($this->rootId);

            // Empty now. Stay alive while work remains (a node is blocked by a
            // running parent, or a dead lane's row is not yet stale). Retire
            // only when the pile is drained.
            if ($pile->hasOpenWork($this->rootId)) {
                self::dispatch($this->rootId, $this->lane, $this->skipElections)
                    ->delay(now()->addSeconds(self::EMPTY_BACKOFF_SECONDS));
            }

            return;
        }

        $status = 'done';
        $reason = null;
        try {
            $has = \Illuminate\Support\Facades\DB::table('legislatures')
                ->where('jurisdiction_id', $row->jurisdiction_id)
                ->whereNull('deleted_at')->exists();
            if (! $has) {
                $exit = Artisan::call('apportionment:seed', ['--jurisdiction' => $row->slug]);
                if ($exit !== 0) {
                    throw new \RuntimeException("apportionment:seed exited {$exit}");
                }
            }
            $bootExit = Artisan::call('jurisdiction:activate', array_filter([
                'slug'          => $row->slug,
                '--force'       => true,
                '--no-election' => $this->skipElections,
            ]));
            if ($bootExit !== 0) {
                $status = 'review';
                $reason = "jurisdiction:activate exited {$bootExit}";
            }
        } catch (\Throwable $e) {
            $status = 'review';
            $reason = mb_substr($e->getMessage(), 0, 400);
        }

        $pile->finalize($row->id, $row->claim_token, $status, $reason);
        $pile->publishProgress($this->rootId);

        // Keep the lane count: one successor while any work remains.
        if ($pile->hasOpenWork($this->rootId)) {
            self::dispatch($this->rootId, $this->lane, $this->skipElections);
        }
    }
}
