<?php

namespace App\Jobs;

use App\Http\Controllers\LegislatureController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ONE LANE of the paradigm-compliant map sweep (operator order 2026-08-29).
 *
 * Claims ONE scope item from the map's cost-ordered pile, draws it through
 * the exact per-scope machinery the stepper uses (executeMassReseedSweep in
 * map_view_all mode — proven survivable at every size because each unit runs
 * with a clean memory slate), records the outcome, then dispatches its own
 * replacement so the lane stays alive while work remains. Two-ended drain:
 * even lanes eat biggest-first (monsters start immediately, crash early if
 * they will), odd lanes eat smallest-first (the smalls never stop).
 *
 * A lane death costs one scope: the item's claim goes stale and any surviving
 * lane reclaims it after STALE_MINUTES. attempts caps runaway retries — a
 * scope that dies three times lands in review with its reason, and the run
 * completes around it (failures flag for review; they never sink the run).
 */
class MapScopeLaneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;   // a monster scope legitimately runs long
    public int $tries = 1;     // retries are the PILE's job, not the queue's

    private const STALE_MINUTES = 45;
    private const MAX_ATTEMPTS  = 3;

    public function __construct(
        public string $mapId,
        public int $lane,
    ) {
        $this->onQueue('autoscale');
    }

    public function handle(): void
    {
        $token = (string) Str::uuid();
        $dir   = $this->lane % 2 === 0 ? 'DESC' : 'ASC';

        // Claim ONE item: pending first, stale-running reclaimed alongside
        // (a dead lane's claim, updated_at silent past the window). Atomic
        // via FOR UPDATE SKIP LOCKED; attempts caps repeat deaths.
        $row = DB::selectOne(
            "
            UPDATE map_scope_items
               SET status = 'running', claim_token = ?, attempts = attempts + 1,
                   started_at = now(), updated_at = now()
             WHERE id = (
                SELECT id FROM map_scope_items
                 WHERE map_id = ?
                   AND (status = 'pending'
                        OR (status = 'running' AND updated_at < now() - interval '" . self::STALE_MINUTES . " minutes'))
                   AND attempts < ?
                 ORDER BY est_cost {$dir}
                 FOR UPDATE SKIP LOCKED
                 LIMIT 1)
            RETURNING id, legislature_id, scope_id, attempts
            ",
            [$token, $this->mapId, self::MAX_ATTEMPTS],
        );

        if ($row === null) {
            $this->completionCheck();

            return;
        }

        $status = 'done';
        $reason = null;
        try {
            $res = app(LegislatureController::class)->executeMassReseedSweep(
                (string) $row->legislature_id,
                'map_view_all',
                (string) $row->scope_id,
                $this->mapId,
                null,   // system filing (one map, one permission model)
                null,   // constitutional default template
                true,
            );
            if (! empty($res['errors'])) {
                $status = 'review';
                $reason = implode(' | ', array_slice((array) $res['errors'], 0, 3));
            }
        } catch (\Throwable $e) {
            $status = 'review';
            $reason = mb_substr($e->getMessage(), 0, 500);
        }

        DB::update(
            "UPDATE map_scope_items
                SET status = ?, reason = ?, finished_at = now(), updated_at = now()
              WHERE id = ? AND claim_token = ?",
            [$status, $reason, $row->id, $token],
        );

        // Keep the lane alive while claimable work remains; the replacement
        // job gives this worker process its recycle point (Horizon's memory
        // check runs between jobs), so no process ever accumulates scopes.
        $more = DB::table('map_scope_items')
            ->where('map_id', $this->mapId)
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
            self::dispatch($this->mapId, $this->lane);
        } else {
            $this->completionCheck();
        }
    }

    private function completionCheck(): void
    {
        $c = DB::selectOne(
            "SELECT COUNT(*) FILTER (WHERE status IN ('pending','running')) AS open,
                    COUNT(*) FILTER (WHERE status = 'done')                 AS done,
                    COUNT(*) FILTER (WHERE status = 'review')               AS review,
                    COUNT(*)                                                AS total
               FROM map_scope_items WHERE map_id = ?",
            [$this->mapId],
        );
        if ((int) $c->open === 0) {
            Log::info('map sweep COMPLETE', [
                'map_id' => $this->mapId,
                'done'   => (int) $c->done,
                'review' => (int) $c->review,
                'total'  => (int) $c->total,
            ]);
        }
    }
}
