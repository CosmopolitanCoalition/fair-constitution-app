<?php

namespace App\Jobs\Media;

use App\Models\MediaPull;
use App\Models\MediaPullItem;
use App\Services\Media\MediaPullPlanner;
use App\Services\Media\MediaTransfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * PullMediaItemJob (W-0448) — transfers ONE media file, then advances the run.
 *
 * Rides the `long-running` queue on `redis-long` (retry_after 14400 s) so a
 * multi-GB master is never ghost-redelivered mid-download. $tries = 1 and
 * $timeout = 0: the transfer owns its own bounded retries and derived
 * per-request timeouts, so the queue must not race it. The job:
 *
 *   1. leaves the item `pending` and exits if the run is halted (a control that
 *      seizes: the item resumes on the next resume/re-dispatch);
 *   2. claims the item atomically (pending -> running, attempts + 1) so two
 *      lanes never fight over the same file;
 *   3. runs the transfer, records done / failed / skipped / pending;
 *   4. increments the run counters atomically and closes the run when no item
 *      is left pending or running;
 *   5. fences every write after the claim to its claim token (attempts), so a
 *      lane whose item was reclaimed and re-claimed records nothing.
 *
 * All state is a committed boundary — a kill mid-run costs one item, not the
 * pass (ETL paradigm: resumable).
 */
class PullMediaItemJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(
        private readonly string $pullId,
        private readonly string $itemId,
    ) {
        $this->onConnection('redis-long');
        $this->onQueue('long-running');
    }

    public function handle(MediaTransfer $transfer): void
    {
        $pull = MediaPull::query()->find($this->pullId);
        $item = MediaPullItem::query()->find($this->itemId);
        if ($pull === null || $item === null) {
            return;
        }

        // Halt seizes: leave the item pending and exit. A resume re-dispatches it.
        if ($pull->status === 'halted') {
            return;
        }

        // Claim atomically: only a still-pending row flips to running. A zero
        // count means another lane already took it (or it is not pending).
        $claimed = DB::table('media_pull_items')
            ->where('id', $this->itemId)
            ->where('status', 'pending')
            ->update([
                'status'     => 'running',
                'attempts'   => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);
        if ($claimed === 0) {
            return;
        }
        $item->refresh();

        // THE CLAIM FENCE: attempts is this lane's claim token. A reclaim can
        // return the item to the pool while this lane is still alive; once
        // another lane claims it (attempts + 1) every write below matches no
        // row, so a superseded lane never records a status or moves a counter.
        $claim = (int) $item->attempts;

        [$kind, $base] = $this->route($pull);

        $result = $transfer->pull($item, $kind, $base);
        $status = $result['status'];
        $bytes = (int) ($result['bytes'] ?? 0);

        if ($status === 'superseded') {
            return;
        }

        if ($status === 'pending') {
            // Halted mid-transfer. Return the item to the pool; no counter move.
            DB::table('media_pull_items')
                ->where('id', $this->itemId)
                ->where('attempts', $claim)
                ->update(['status' => 'pending', 'bytes_done' => $bytes, 'updated_at' => now()]);

            return;
        }

        $recorded = DB::table('media_pull_items')
            ->where('id', $this->itemId)
            ->where('attempts', $claim)
            ->update([
                'status'     => $status,
                'bytes_done' => $bytes,
                'error'      => $result['error'] ?? null,
                'updated_at' => now(),
            ]);
        if ($recorded === 0) {
            return;                              // superseded at the last step
        }

        // A done or skipped file advances items_done; only a real fetch adds
        // its bytes to the run total (a skip was already on disk).
        if ($status === 'done' || $status === 'skipped') {
            DB::table('media_pulls')->where('id', $this->pullId)->update([
                'items_done' => DB::raw('items_done + 1'),
                'bytes_done' => DB::raw('bytes_done + '.($status === 'done' ? max(0, $bytes) : 0)),
                'updated_at' => now(),
            ]);
        } elseif ($status === 'failed') {
            DB::table('media_pulls')->where('id', $this->pullId)->increment('items_failed', 1, ['updated_at' => now()]);
        }

        // The planner owns the close: it first returns any stale claim (a
        // sibling lane killed mid-item) to the pool, then closes a drained run.
        app(MediaPullPlanner::class)->settle($this->pullId);
    }

    /**
     * The transfer route from the run: a web download reads from the website
     * origin (the run's source_ref, else the configured base); a folder copy
     * reads from the run's source_ref path.
     *
     * @return array{0: string, 1: string}
     */
    private function route(MediaPull $pull): array
    {
        if ($pull->source === 'folder') {
            return ['folder', (string) ($pull->source_ref ?? '')];
        }

        $base = (string) ($pull->source_ref ?? '');
        if ($base === '') {
            $base = app(\App\Services\Media\MediaLibraryService::class)->websiteBase();
        }

        return ['web', $base];
    }
}
