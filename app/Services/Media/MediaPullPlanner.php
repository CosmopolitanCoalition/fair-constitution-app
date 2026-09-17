<?php

namespace App\Services\Media;

use App\Jobs\Media\PullMediaItemJob;
use App\Models\MediaPull;
use App\Models\MediaPullItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MediaPullPlanner (W-0448) — the ONE owner of run creation and dispatch, so
 * the Step-2 controller's `start` and the `media:pull` command produce the same
 * run (single-owner rail). It:
 *
 *   - plans items from the catalog (MediaLibraryService::planItems);
 *   - creates the run row and inserts items in bounded chunks (500), a file
 *     already on disk landing as `skipped` (already counted done);
 *   - dispatches pending items two-ended so a monster (a master) starts at
 *     once AND a light (a caption) starts at once — the smalls never stop.
 *
 * A folder run stats each source file (a cheap local stat) so bytes_expected is
 * real and the two-ended order is by true size; a web run leaves bytes_expected
 * null and the transfer fills it from the first response, so the order falls
 * back to master > audio > captions.
 */
class MediaPullPlanner
{
    /** Bounded insert size (ETL paradigm: never one unchunked statement). */
    private const INSERT_CHUNK = 500;

    public function __construct(private readonly MediaLibraryService $library) {}

    /** True when a run is already running or halted (only one at a time). */
    public function activePull(): ?MediaPull
    {
        return MediaPull::query()
            ->whereIn('status', ['running', 'halted'])
            ->orderByDesc('created_at')
            ->first();
    }

    /** The latest halted run, or null. */
    public function haltedPull(): ?MediaPull
    {
        return MediaPull::query()->where('status', 'halted')->orderByDesc('created_at')->first();
    }

    /** The latest run of any status, or null. */
    public function latestPull(): ?MediaPull
    {
        return MediaPull::query()->orderByDesc('created_at')->first();
    }

    /**
     * Resume a halted run: flip it to running and (unless $dispatch is false,
     * the --sync path) dispatch its pending items. The ONE owner of resume for
     * the wizard control and the media:pull --resume flag (WoS 2026-09-17:
     * the CLI refused a halted run because activePull() counts halted as
     * active, and only the wizard could resume). Returns the count dispatched.
     */
    public function resume(MediaPull $pull, bool $dispatch = true): int
    {
        if ($pull->status !== 'halted') {
            return 0;
        }
        $pull->forceFill(['status' => 'running', 'finished_at' => null, 'updated_at' => now()])->save();

        return $dispatch ? $this->dispatchPending($pull) : 0;
    }

    /**
     * Reset a run's failed items to pending, clear the failed counter, mark
     * the run running and (unless $dispatch is false) dispatch. Returns
     * [reset, dispatched]. The single owner for the wizard and the CLI.
     *
     * @return array{0: int, 1: int}
     */
    public function retryFailed(MediaPull $pull, bool $dispatch = true): array
    {
        $reset = DB::table('media_pull_items')
            ->where('pull_id', $pull->id)
            ->where('status', 'failed')
            ->update(['status' => 'pending', 'error' => null, 'updated_at' => now()]);
        if ($reset > 0) {
            $pull->forceFill([
                'status'       => 'running',
                'items_failed' => 0,
                'finished_at'  => null,
                'updated_at'   => now(),
            ])->save();
        }

        return [$reset, $dispatch ? $this->dispatchPending($pull) : 0];
    }

    /**
     * Create a run and its items. Does NOT dispatch — the caller decides sync
     * or queued.
     *
     * @param  list<string>|null  $subjects
     * @param  list<string>  $kinds
     * @param  array<string, mixed>  $options
     */
    public function create(
        string $source,
        ?array $subjects,
        array $kinds,
        ?string $sourceRef,
        ?string $initiatorUserId = null,
        array $options = [],
    ): MediaPull {
        $plan = $this->library->planItems($subjects, $kinds);

        $folderBase = $source === 'folder' ? (string) ($sourceRef ?? '') : null;

        $pull = new MediaPull;
        $pull->id = (string) Str::uuid();
        $pull->source = $source;
        $pull->source_ref = $sourceRef;
        $pull->status = 'running';
        $pull->options = $options;
        $pull->items_total = count($plan);
        $pull->items_done = 0;
        $pull->items_failed = 0;
        $pull->bytes_total = 0;
        $pull->bytes_done = 0;
        $pull->initiator_user_id = $initiatorUserId;
        $pull->started_at = now();
        $pull->save();

        $skipped = 0;
        $bytesTotal = 0;
        $rows = [];
        $now = now();

        foreach ($plan as $p) {
            $expected = null;
            if ($folderBase !== null && $folderBase !== '') {
                $rel = ltrim($p['source_path'], '/');
                $abs = rtrim(str_replace('\\', '/', $folderBase), '/').'/'.$rel;
                if (! is_file($abs) && stripos($rel, 'Subjects/') === 0) {
                    // A base that points AT the Subjects folder (MEDIA_SOURCE_DIR=E:/Subjects).
                    $abs = rtrim(str_replace('\\', '/', $folderBase), '/').'/'.substr($rel, strlen('Subjects/'));
                }
                if (is_file($abs)) {
                    $expected = (int) filesize($abs);
                    $bytesTotal += $expected;
                }
            }

            $rows[] = [
                'id'             => (string) Str::uuid(),
                'pull_id'        => $pull->id,
                'subject'        => $p['subject'],
                'kind'           => $p['kind'],
                'track_name'     => $p['track_name'],
                'source'         => $p['source_path'],
                'dest'           => $p['dest'],
                'bytes_expected' => $expected,
                'bytes_done'     => 0,
                'status'         => $p['status'],
                'attempts'       => 0,
                'error'          => null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];

            if ($p['status'] === 'skipped') {
                $skipped++;
            }

            if (count($rows) >= self::INSERT_CHUNK) {
                DB::table('media_pull_items')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('media_pull_items')->insert($rows);
        }

        // A run that had nothing to do (every file present) is done at birth.
        $pending = $pull->items_total - $skipped;
        $pull->items_done = $skipped;
        $pull->bytes_total = $bytesTotal;
        if ($pending === 0) {
            $pull->status = 'done';
            $pull->finished_at = now();
        }
        $pull->save();

        return $pull;
    }

    /**
     * Dispatch every pending item of a run as a queued job, two-ended. Returns
     * the count dispatched.
     */
    public function dispatchPending(MediaPull $pull): int
    {
        if ($pull->status === 'halted') {
            return 0;
        }

        $items = MediaPullItem::query()
            ->where('pull_id', $pull->id)
            ->where('status', 'pending')
            ->get();

        $ordered = $this->twoEnded($items->all());

        foreach ($ordered as $item) {
            PullMediaItemJob::dispatch($pull->id, $item->id);
        }

        return count($ordered);
    }

    /**
     * Run every pending item inline, in the two-ended order, calling
     * $progress(item, result, index, total) after each. For a box with no
     * Horizon (the --sync path).
     */
    public function runSync(MediaPull $pull, MediaTransfer $transfer, callable $progress): void
    {
        $items = MediaPullItem::query()
            ->where('pull_id', $pull->id)
            ->where('status', 'pending')
            ->get();

        $ordered = $this->twoEnded($items->all());
        $total = count($ordered);

        foreach ($ordered as $i => $item) {
            $fresh = MediaPull::query()->find($pull->id);
            if ($fresh === null || $fresh->status === 'halted') {
                break;
            }

            DB::table('media_pull_items')->where('id', $item->id)
                ->where('status', 'pending')
                ->update(['status' => 'running', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
            $item->refresh();

            [$kind, $base] = $pull->source === 'folder'
                ? ['folder', (string) ($pull->source_ref ?? '')]
                : ['web', (string) ($pull->source_ref ?: $this->library->websiteBase())];

            $result = $transfer->pull($item, $kind, $base);
            $status = $result['status'];
            $bytes = (int) ($result['bytes'] ?? 0);

            if ($status === 'pending') {
                DB::table('media_pull_items')->where('id', $item->id)
                    ->update(['status' => 'pending', 'bytes_done' => $bytes, 'updated_at' => now()]);
                $progress($item, $result, $i + 1, $total);
                break;
            }

            DB::table('media_pull_items')->where('id', $item->id)->update([
                'status' => $status, 'bytes_done' => $bytes,
                'error' => $result['error'] ?? null, 'updated_at' => now(),
            ]);

            if ($status === 'done' || $status === 'skipped') {
                DB::table('media_pulls')->where('id', $pull->id)->update([
                    'items_done' => DB::raw('items_done + 1'),
                    'bytes_done' => DB::raw('bytes_done + '.($status === 'done' ? max(0, $bytes) : 0)),
                    'updated_at' => now(),
                ]);
            } elseif ($status === 'failed') {
                DB::table('media_pulls')->where('id', $pull->id)->increment('items_failed', 1, ['updated_at' => now()]);
            }

            $progress($item, $result, $i + 1, $total);
        }

        $this->closeIfDrained($pull->id);
    }

    /**
     * Two-ended order over a sorted pile: largest, smallest, next-largest,
     * next-smallest, ... Sorted by bytes_expected when known, else by kind
     * rank (master > audio > captions), then subject, then track for a stable
     * deterministic order.
     *
     * @param  list<MediaPullItem>  $items
     * @return list<MediaPullItem>
     */
    private function twoEnded(array $items): array
    {
        usort($items, function (MediaPullItem $a, MediaPullItem $b): int {
            $ae = $a->bytes_expected;
            $be = $b->bytes_expected;
            if ($ae !== null && $be !== null && $ae !== $be) {
                return $be <=> $ae; // largest first
            }
            $rank = ['master' => 0, 'audio' => 1, 'captions' => 2];
            $ar = $rank[$a->kind] ?? 3;
            $br = $rank[$b->kind] ?? 3;
            if ($ar !== $br) {
                return $ar <=> $br;
            }
            if ($a->subject !== $b->subject) {
                return strcmp((string) $a->subject, (string) $b->subject);
            }

            return strcmp((string) $a->track_name, (string) $b->track_name);
        });

        $out = [];
        $lo = 0;
        $hi = count($items) - 1;
        $takeLow = true;
        while ($lo <= $hi) {
            $out[] = $takeLow ? $items[$lo++] : $items[$hi--];
            $takeLow = ! $takeLow;
        }

        return $out;
    }

    private function closeIfDrained(string $pullId): void
    {
        $remaining = DB::table('media_pull_items')
            ->where('pull_id', $pullId)
            ->whereIn('status', ['pending', 'running'])
            ->count();
        if ($remaining > 0) {
            return;
        }
        $failed = DB::table('media_pull_items')
            ->where('pull_id', $pullId)
            ->where('status', 'failed')
            ->count();

        DB::table('media_pulls')
            ->where('id', $pullId)
            ->where('status', 'running')
            ->update([
                'status'      => $failed > 0 ? 'failed' : 'done',
                'finished_at' => now(),
                'updated_at'  => now(),
            ]);
    }
}
