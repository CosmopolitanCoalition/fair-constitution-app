<?php

namespace App\Services;

use App\Jobs\ActivateSubtreeJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The control seam for the multi-lane subtree boot (W-0253).
 *
 * The pile is `subtree_boot_items`: one row per node in the walk, claimed
 * by lanes. This service owns the three claim decisions the lane job used
 * to hold inline as raw PostgreSQL, so each is portable and pinned:
 *
 *  - claim(): PER-PARENT readiness, not a global depth barrier. A node is
 *    claimable when its own parent row in the pile is terminal (done or
 *    review), or it has no parent in the pile (the root). The old barrier
 *    admitted only the single shallowest open depth, so every wave below
 *    the root collapsed to one lane. Per-parent readiness opens all the
 *    ready siblings at once, so a wave keeps as many lanes as it has ready
 *    nodes. A parent still always boots before its children.
 *
 *  - reclaimStuck(): a running row that has gone stale AND used all its
 *    attempts is moved to review. Before this a three-strike running row
 *    stayed running forever, pinning the lane and holding the pile open.
 *
 *  - reset(): the escape hatch. It SEIZES: it deletes the pile for the
 *    root and clears the progress cache regardless of any running row. A
 *    lane that finishes an abandoned claim writes nothing, because its
 *    row is gone.
 *
 * lockForUpdate is a no-op on sqlite, so the claim is testable there and
 * still race-safe on PostgreSQL.
 */
class SubtreeBootService
{
    /** Minutes a running claim may be idle before another lane may seize it. */
    public const STALE_MINUTES = 20;

    /** Claims per node before it is reclaimed to review. */
    public const MAX_ATTEMPTS = 3;

    /**
     * Move every three-strike stale running row to review so it stops
     * pinning the pile. Returns the number reclaimed.
     */
    public function reclaimStuck(string $rootId): int
    {
        return DB::table('subtree_boot_items')
            ->where('root_id', $rootId)
            ->where('status', 'running')
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update([
                'status'      => 'review',
                'reason'      => 'stuck: reclaimed after '.self::MAX_ATTEMPTS.' attempts',
                'finished_at' => now(),
                'updated_at'  => now(),
            ]);
    }

    /**
     * Claim ONE ready node for a lane. Returns {id, jurisdiction_id, slug,
     * claim_token} or null when nothing is claimable right now.
     *
     * Ready = pending, or running-but-stale (a dead lane's row, up to
     * MAX_ATTEMPTS), AND the node's parent in the pile is terminal.
     */
    public function claim(string $rootId): ?object
    {
        // Terminal-out the stuck rows first so a lane never waits on one.
        $this->reclaimStuck($rootId);

        $token = (string) Str::uuid();
        $staleBefore = now()->subMinutes(self::STALE_MINUTES);

        return DB::transaction(function () use ($rootId, $token, $staleBefore) {
            $candidate = DB::table('subtree_boot_items as b')
                ->leftJoin('jurisdictions as j', 'j.id', '=', 'b.jurisdiction_id')
                ->leftJoin('subtree_boot_items as p', function ($join) {
                    $join->on('p.root_id', '=', 'b.root_id')
                        ->on('p.jurisdiction_id', '=', 'j.parent_id');
                })
                ->where('b.root_id', $rootId)
                ->where('b.attempts', '<', self::MAX_ATTEMPTS)
                ->where(function ($q) use ($staleBefore) {
                    $q->where('b.status', 'pending')
                        ->orWhere(function ($w) use ($staleBefore) {
                            $w->where('b.status', 'running')->where('b.updated_at', '<', $staleBefore);
                        });
                })
                ->where(function ($q) {
                    // Root (no parent row in the pile) or a parent that is done.
                    $q->whereNull('p.id')->orWhereIn('p.status', ['done', 'review']);
                })
                ->orderBy('b.depth')
                ->orderBy('b.slug')
                ->lockForUpdate()
                ->select('b.id', 'b.jurisdiction_id', 'b.slug')
                ->first();

            if ($candidate === null) {
                return null;
            }

            $affected = DB::table('subtree_boot_items')
                ->where('id', $candidate->id)
                ->where(function ($q) use ($staleBefore) {
                    $q->where('status', 'pending')
                        ->orWhere(function ($w) use ($staleBefore) {
                            $w->where('status', 'running')->where('updated_at', '<', $staleBefore);
                        });
                })
                ->update([
                    'status'      => 'running',
                    'claim_token' => $token,
                    'attempts'    => DB::raw('attempts + 1'),
                    'updated_at'  => now(),
                ]);

            if ($affected === 0) {
                return null; // lost the row to another lane
            }

            return (object) [
                'id'              => $candidate->id,
                'jurisdiction_id' => $candidate->jurisdiction_id,
                'slug'            => $candidate->slug,
                'claim_token'     => $token,
            ];
        });
    }

    /**
     * Record a claim's outcome. The claim_token guard makes a late write
     * from a reclaimed lane a no-op.
     */
    public function finalize(string $rowId, string $token, string $status, ?string $reason = null): void
    {
        DB::table('subtree_boot_items')
            ->where('id', $rowId)
            ->where('claim_token', $token)
            ->update([
                'status'      => $status,
                'reason'      => $reason,
                'finished_at' => now(),
                'updated_at'  => now(),
            ]);
    }

    /**
     * True while any node is still pending or running. A lane that finds
     * nothing to claim stays alive while this holds (a node is blocked by a
     * running parent, or a dead lane's row is not yet stale), and retires
     * only when it is false.
     */
    public function hasOpenWork(string $rootId): bool
    {
        return DB::table('subtree_boot_items')
            ->where('root_id', $rootId)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    /** Publish the same progress shape the row's mini bar has always polled. */
    public function publishProgress(string $rootId): array
    {
        $c = DB::table('subtree_boot_items')
            ->where('root_id', $rootId)
            ->selectRaw("count(*) as total")
            ->selectRaw("count(case when status in ('done','review') then 1 end) as processed")
            ->selectRaw("count(case when status = 'done' then 1 end) as booted")
            ->first();

        $total = (int) ($c->total ?? 0);
        $processed = (int) ($c->processed ?? 0);
        $finished = $processed >= $total;

        $payload = [
            'total'     => $total,
            'processed' => $processed,
            'booted'    => (int) ($c->booted ?? 0),
            'finished'  => $finished,
        ];

        Cache::put(ActivateSubtreeJob::progressKey($rootId), $payload, $finished ? 120 : 7200);

        return $payload;
    }

    /**
     * The escape hatch. Delete the pile for the root and clear its progress
     * cache. It never waits on a running row. Returns rows removed.
     */
    public function reset(string $rootId): int
    {
        $removed = DB::table('subtree_boot_items')->where('root_id', $rootId)->delete();
        Cache::forget(ActivateSubtreeJob::progressKey($rootId));

        return $removed;
    }
}
