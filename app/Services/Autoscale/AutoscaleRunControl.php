<?php

namespace App\Services\Autoscale;

use App\Models\AutoscaleRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shared halt/resume control for the full-scale autoscale run — the ONE code
 * path behind BOTH the Step-3 dashboard buttons (SetupController::autoscaleHalt
 * / autoscaleResume) and the `autoscale:halt` / `autoscale:resume` CLIs. UI↔CLI
 * parity means the window checks and item bookkeeping travel with the pair;
 * extracting them here (the SimRunControl precedent) is what keeps the two
 * doors from drifting.
 *
 * The operator gate lives with the CALLER, by design: the controller enforces
 * `is_operator` on the request; the CLI is operator-trusted by construction
 * (it runs in the box shell). This service performs the run state change only —
 * identical whichever door called it.
 */
class AutoscaleRunControl
{
    /**
     * Halt the active run: stamp halt_requested_at and pump once so the run
     * parks now rather than at the next scheduled tick.
     *
     * @return array{ok:bool, error?:string}
     */
    public function halt(): array
    {
        $run = AutoscaleRun::unfinished();
        if ($run === null) {
            return ['ok' => false, 'error' => 'No active autoscale run.'];
        }
        $run->forceFill(['halt_requested_at' => now()])->save();
        Artisan::call('autoscale:pump'); // park it now, not in a minute

        return ['ok' => true, 'run_id' => (string) $run->id];
    }

    /**
     * Resume a halted run. With $requeueReview it also revives a DONE run's
     * review/failed/halted items (dropping their stale scope trees) so the
     * pump re-mints fresh root scopes — the dashboard's "Retry all review
     * items" path. Clears halt_requested_at and pumps.
     *
     * @return array{ok:bool, run_id?:string, error?:string}
     */
    public function resume(bool $requeueReview = false): array
    {
        $run = AutoscaleRun::unfinished()
            ?? ($requeueReview
                ? AutoscaleRun::query()->where('status', 'done')->orderByDesc('created_at')->first()
                : null);
        if ($run === null) {
            return ['ok' => false, 'error' => 'No autoscale run to resume.'];
        }

        if ($requeueReview) {
            // STATUS-CLEAR, NEVER ROW-DELETE (single-home law, 2026-08-31):
            // scope rows are FACTS; a retry resets their work state in
            // bounded chunks. A gate-refused header returns to review, not
            // pending — a refusal only moves when the data changes.
            $requeued = DB::table('apportionment_ledger')
                ->whereIn('map_status', ['review', 'failed'])
                ->whereNull('gate_reason')
                ->pluck('legislature_id');
            foreach ($requeued->chunk(5000) as $chunk) {
                DB::table('apportionment_ledger_scopes')
                    ->whereIn('legislature_id', $chunk)
                    ->update([
                        'status' => 'pending', 'claim_token' => null, 'reason' => null,
                        'started_at' => null, 'finished_at' => null,
                        'retry_count' => 0, 'updated_at' => now(),
                    ]);
                DB::table('apportionment_ledger')
                    ->whereIn('legislature_id', $chunk)
                    ->update([
                        'map_status'  => 'pending', 'reason' => null,
                        'claim_token' => null, 'updated_at' => now(),
                    ]);
            }
        }

        if ($run->status === 'done') {
            $run->forceFill(['status' => 'mapping', 'finished_at' => null])->save();
        }

        // THE FRESH CLOCK (operator rule 2026-08-31, "reset to 0 before
        // starting so I can gauge proper time"): a zero-progress run is a
        // fresh benchmark — its clock stamps at the GO, not at the reset.
        // A mid-run resume (progress exists) keeps its original start.
        $fresh = (int) $run->sweeps_done === 0 && (int) $run->singles_done === 0;
        $run->forceFill([
            'halt_requested_at'  => null,
            'mapping_started_at' => $fresh ? now() : $run->mapping_started_at,
        ])->save();
        Artisan::call('autoscale:pump');

        return ['ok' => true, 'run_id' => (string) $run->id];
    }

    // ── Lane kill controls (operator order 2026-09-02) ─────────────────────
    //
    // Deadlines are WARNINGS. A kill is manual (the lane's kill button, the
    // controller) or opt-in automatic (autoscale_runs.auto_kill_minutes, the
    // pump). A killed scope PARKS in review, no retry. ONE implementation,
    // killLease(), serves both doors and the pump's per-minute sweep.

    /**
     * Set or clear the automatic kill limit on the unfinished run.
     *
     * @return array{ok:bool, auto_kill_minutes?:int|null, error?:string}
     */
    public function setAutoKillMinutes(?int $minutes): array
    {
        $run = AutoscaleRun::unfinished();
        if ($run === null) {
            return ['ok' => false, 'error' => 'No active autoscale run.'];
        }
        AutoscaleRun::query()->whereKey($run->id)->update([
            'auto_kill_minutes' => $minutes,
            'updated_at'        => now(),
        ]);
        Log::info('Autoscale auto-kill limit set', ['run_id' => (string) $run->id, 'minutes' => $minutes]);

        return ['ok' => true, 'auto_kill_minutes' => $minutes];
    }

    /**
     * Kill one lane by lease id.
     *
     *  1. When the lease records a connected postgres backend, terminate it
     *     (same role only, never this process's own backend). The lane's
     *     connection guard refuses the framework's transparent retry, the
     *     worker's catch sees the lost session, and the lane ends with no
     *     write to the lost scope (AutoscaleWorkerJob).
     *  2. THE SCOPE IN HAND parks in review with the kill reason,
     *     claim_token NULL, no retry. That is the lease's current_scope_id:
     *     a scope claim's scope, or the batch scope the lane was working.
     *     Every other running scope under this token (a batch's untouched
     *     remainder) returns to pending with no retry bump: it was never
     *     worked.
     *  3. The parked scope's header is handed to the finalize rung, the
     *     processor's one review path.
     *  4. The lease row is deleted.
     *
     * $reasonPrefix is 'killed by operator' or 'auto-killed'; the minutes
     * the scope in hand ran (claim_started_at) are appended.
     *
     * @return array{ok:bool, terminated:bool, parked:int, released:int, headers_handed:int}|null null when no such lease
     */
    public function killLease(string $leaseId, string $reasonPrefix, ?int $limitMinutes = null): ?array
    {
        $lease = DB::table('autoscale_worker_leases')->where('id', $leaseId)->first();
        if ($lease === null) {
            return null;
        }

        $minutes = $lease->claim_started_at !== null
            ? (int) floor(max(0, now()->getTimestamp() - \Illuminate\Support\Carbon::parse($lease->claim_started_at)->getTimestamp()) / 60)
            : 0;
        $reason = $reasonPrefix.' after '.$minutes.' min'
            .($limitMinutes !== null ? ' (limit '.$limitMinutes.')' : '');

        $terminated = false;
        if ($lease->pg_backend_pid !== null) {
            // Same role only (pg_terminate_backend's own rule for non-
            // superusers) and never the caller's own backend.
            $row = DB::selectOne('
                SELECT COUNT(*) FILTER (WHERE pg_terminate_backend(a.pid)) AS n
                  FROM pg_stat_activity a
                 WHERE a.pid = ?
                   AND a.pid <> pg_backend_pid()
                   AND a.usename = current_user
            ', [(int) $lease->pg_backend_pid]);
            $terminated = (int) ($row->n ?? 0) > 0;
        }

        // THE SCOPE IN HAND PARKS, THE REMAINDER RETURNS (review repair
        // 2026-09-02): a lane works ONE scope at a time and records it as
        // current_scope_id. Only that scope parks. A lane from before the
        // column existed records none; for a plain scope claim its one
        // running scope IS the work and parks.
        $current = $lease->current_scope_id ?? null;
        if ($current === null && $lease->claim_type === 'scope') {
            $current = DB::table('apportionment_ledger_scopes')
                ->where('claim_token', $leaseId)
                ->where('status', 'running')
                ->value('id');
        }
        $parked = $current === null ? [] : DB::select("
            UPDATE apportionment_ledger_scopes
               SET status = 'review', claim_token = NULL, reason = ?,
                   finished_at = now(), updated_at = now()
             WHERE id = ?::uuid AND claim_token = ?::uuid AND status = 'running'
         RETURNING id, legislature_id
        ", [$reason, (string) $current, $leaseId]);

        // A batch's untouched remainder: back to pending, no retry bump
        // (the worker's own releaseBatchRemainder shape).
        $released = DB::table('apportionment_ledger_scopes')
            ->where('claim_token', $leaseId)
            ->where('status', 'running')
            ->update(['status' => 'pending', 'claim_token' => null, 'started_at' => null, 'updated_at' => now()]);

        // The parked scope's header goes to the finalize rung: the
        // assessment finds the undrawn territory and closes the header as
        // review with the kill reason riding in as a diagnostic.
        $processor = app(SweepScopeProcessor::class);
        $handed = 0;
        foreach (array_unique(array_map(static fn ($r) => (string) $r->legislature_id, $parked)) as $legId) {
            if ($processor->handHeaderToFinalize($legId)) {
                $handed++;
            }
        }

        // Non-scope claims held by the lease RELEASE (the worker's own
        // releaseClaim shape): a singles batch or an assessment is not a
        // drawing that failed, so it returns to the pile, never to review.
        DB::table('apportionment_ledger')
            ->where('claim_token', $leaseId)
            ->where('kind', 'single')
            ->where('map_status', 'running')
            ->update(['map_status' => 'pending', 'claim_token' => null, 'updated_at' => now()]);
        DB::table('apportionment_ledger')
            ->where('claim_token', $leaseId)
            ->where('map_status', 'assessing')
            ->update(['map_status' => 'running', 'claim_token' => null, 'updated_at' => now()]);
        DB::table('jurisdiction_adjacency_parents')
            ->where('claim_token', $leaseId)
            ->where('status', 'running')
            ->update(['status' => 'pending', 'claim_token' => null, 'updated_at' => now()]);

        DB::table('autoscale_worker_leases')->where('id', $leaseId)->delete();

        Log::warning('Autoscale lane killed', [
            'lease'          => $leaseId,
            'run_id'         => (string) $lease->run_id,
            'claim_type'     => $lease->claim_type,
            'claim_label'    => $lease->claim_label,
            'claim_minutes'  => $minutes,
            'reason'         => $reason,
            'terminated'     => $terminated,
            'parked_scope'   => $parked !== [] ? (string) $parked[0]->id : null,
            'released_scopes' => $released,
            'headers_handed' => $handed,
        ]);

        return [
            'ok' => true, 'terminated' => $terminated,
            'parked' => count($parked), 'released' => $released, 'headers_handed' => $handed,
        ];
    }

    /**
     * The pump's per-minute kill sweep for one run: every lease with
     * kill_requested_at set, and, when auto_kill_minutes is set, every
     * scope / scope_batch claim whose scope in hand (claim_started_at, which
     * the lane restarts before each batch scope) is older than the limit.
     * Singles claims are never auto-killed. Returns the number of leases
     * killed.
     */
    public function sweepKills(AutoscaleRun $run): int
    {
        // Column-safe before the lane-kill migration is applied: the pump
        // runs every minute on every box, so a missing column reads as
        // "no kill controls yet", never as a failing pump.
        if (! self::laneControlColumnsPresent()) {
            return 0;
        }

        $killed = 0;

        $requested = DB::table('autoscale_worker_leases')
            ->where('run_id', $run->id)
            ->whereNotNull('kill_requested_at')
            ->pluck('id');
        foreach ($requested as $leaseId) {
            if ($this->killLease((string) $leaseId, 'killed by operator') !== null) {
                $killed++;
            }
        }

        $limit = $run->getAttributes()['auto_kill_minutes'] ?? null;
        if ($limit !== null && (int) $limit > 0) {
            $overdue = DB::table('autoscale_worker_leases')
                ->where('run_id', $run->id)
                ->whereIn('claim_type', ['scope', 'scope_batch'])
                ->whereNotNull('claim_started_at')
                ->where('claim_started_at', '<', now()->subMinutes((int) $limit))
                ->pluck('id');
            foreach ($overdue as $leaseId) {
                if ($this->killLease((string) $leaseId, 'auto-killed', (int) $limit) !== null) {
                    $killed++;
                }
            }
        }

        return $killed;
    }

    private static bool $laneControlColumnsPresent = false;

    /**
     * Are the lane-control columns (2026_09_02_000002) present? Sticky once
     * true; probed again on every call while false, so a long-lived process
     * picks the migration up without a restart. Used by the pump's kill
     * sweep and by the worker's lease touches (current_scope_id).
     */
    public static function laneControlColumnsPresent(): bool
    {
        if (self::$laneControlColumnsPresent) {
            return true;
        }
        self::$laneControlColumnsPresent = \Illuminate\Support\Facades\Schema::hasColumn('autoscale_worker_leases', 'kill_requested_at')
            && \Illuminate\Support\Facades\Schema::hasColumn('autoscale_worker_leases', 'current_scope_id')
            && \Illuminate\Support\Facades\Schema::hasColumn('autoscale_runs', 'auto_kill_minutes');

        return self::$laneControlColumnsPresent;
    }
}
