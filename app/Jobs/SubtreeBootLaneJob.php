<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ONE LANE of the multi-lane subtree boot (operator ruling 2026-08-29, A4).
 *
 * Claims ONE node from the SHALLOWEST open depth of the root's pile — a
 * parent always boots before any of its children, because a deeper depth
 * only opens once every shallower item is terminal — runs the same
 * per-node boot the serial walk ran (seed if chamber missing, then
 * WF-JUR-01 activation; founding elections only where the mode says voters
 * exist), records the outcome, publishes progress, and dispatches its own
 * replacement while claimable work remains. A lane death costs one node:
 * stale claims reclaim after STALE_MINUTES, three strikes lands in review.
 */
class SubtreeBootLaneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;
    public int $tries = 1;   // retries are the pile's job

    private const STALE_MINUTES = 20;
    private const MAX_ATTEMPTS  = 3;

    public function __construct(
        public string $rootId,
        public int $lane,
        public bool $skipElections,
    ) {
        $this->onQueue('autoscale');
    }

    public function handle(): void
    {
        $token = (string) Str::uuid();

        // The depth-wave barrier: claim pending only at the lowest depth
        // that still holds ANY open item — running rows at depth d keep d
        // the minimum, so depth d+1 cannot start until d is terminal.
        $row = DB::selectOne(
            "
            UPDATE subtree_boot_items
               SET status = 'running', claim_token = ?, attempts = attempts + 1,
                   updated_at = now()
             WHERE id = (
                SELECT id FROM subtree_boot_items
                 WHERE root_id = ?
                   AND depth = (SELECT MIN(depth) FROM subtree_boot_items
                                 WHERE root_id = ? AND status IN ('pending', 'running'))
                   AND (status = 'pending'
                        OR (status = 'running' AND updated_at < now() - interval '" . self::STALE_MINUTES . " minutes'))
                   AND attempts < ?
                 ORDER BY slug
                 FOR UPDATE SKIP LOCKED
                 LIMIT 1)
            RETURNING id, jurisdiction_id, slug
            ",
            [$token, $this->rootId, $this->rootId, self::MAX_ATTEMPTS],
        );

        if ($row === null) {
            $this->publish();

            return;
        }

        $status = 'done';
        $reason = null;
        try {
            $has = DB::table('legislatures')
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

        DB::update(
            "UPDATE subtree_boot_items
                SET status = ?, reason = ?, finished_at = now(), updated_at = now()
              WHERE id = ? AND claim_token = ?",
            [$status, $reason, $row->id, $token],
        );
        $this->publish();

        $more = DB::table('subtree_boot_items')
            ->where('root_id', $this->rootId)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(function ($q) {
                $q->where('status', 'pending')
                  ->orWhere(function ($w) {
                      $w->where('status', 'running')
                        ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES));
                  });
            })
            ->exists();
        if ($more) {
            self::dispatch($this->rootId, $this->lane, $this->skipElections);
        }
    }

    /** Same cache shape the row's mini bar has always polled. */
    private function publish(): void
    {
        $c = DB::selectOne(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status IN ('done','review')) AS processed,
                    COUNT(*) FILTER (WHERE status = 'done')             AS booted,
                    COUNT(*) FILTER (WHERE status = 'review')           AS failed
               FROM subtree_boot_items WHERE root_id = ?",
            [$this->rootId],
        );
        $finished = (int) $c->processed >= (int) $c->total;
        Cache::put(ActivateSubtreeJob::progressKey($this->rootId), [
            'total'     => (int) $c->total,
            'processed' => (int) $c->processed,
            'booted'    => (int) $c->booted,
            'finished'  => $finished,
        ], $finished ? 120 : 7200);
        if ($finished) {
            Log::info('SubtreeBoot COMPLETE', [
                'root'   => $this->rootId,
                'booted' => (int) $c->booted,
                'review' => (int) $c->failed,
                'total'  => (int) $c->total,
            ]);
        }
    }
}
