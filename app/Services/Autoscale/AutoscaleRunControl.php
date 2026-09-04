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

    /**
     * Put review/failed maps back on the pile MID-RUN, at priority so the
     * lanes take them next (operator order 2026-09-03: "queue one at a time
     * ... and queue all, mid run"). No resume: the run is already mapping.
     * Same status-clear as resume(requeueReview), minus the run-status change,
     * plus priority_at so the claim ladder pops them ahead of the walk.
     *
     * @param  array<int,string>|null  $legislatureIds  null = every review/failed map
     * @return array{ok:bool, requeued?:int, error?:string}
     */
    public function requeueReviewMaps(?array $legislatureIds = null): array
    {
        $run = AutoscaleRun::unfinished();
        if ($run === null) {
            return ['ok' => false, 'error' => 'No active autoscale run.'];
        }

        $ids = DB::table('apportionment_ledger')
            ->whereIn('map_status', ['review', 'failed'])
            ->whereNull('gate_reason')   // a gate refusal only moves when the data changes
            ->when($legislatureIds !== null, fn ($q) => $q->whereIn('legislature_id', $legislatureIds))
            ->pluck('legislature_id');

        $n = 0;
        foreach ($ids->chunk(5000) as $chunk) {
            DB::table('apportionment_ledger_scopes')
                ->whereIn('legislature_id', $chunk)
                ->update([
                    'status' => 'pending', 'claim_token' => null, 'reason' => null,
                    'started_at' => null, 'finished_at' => null,
                    'retry_count' => 0, 'updated_at' => now(),
                ]);
            $n += DB::table('apportionment_ledger')
                ->whereIn('legislature_id', $chunk)
                ->update([
                    'map_status'  => 'pending', 'reason' => null, 'claim_token' => null,
                    'priority_at' => now(), 'updated_at' => now(),
                ]);
        }
        Artisan::call('autoscale:pump');

        return ['ok' => true, 'requeued' => $n];
    }

    /**
     * Redraw completed maps that finalized with a nonzero drift (operator
     * order 2026-09-03: "run them through"). recheckDrift only re-sums the
     * existing districts; this REQUEUES the drifted done header and its scope
     * tree to pending at priority, so the lanes draw the map afresh with the
     * current engine. Used once the engine can seat a map that previously
     * drifted (the drift-gated pool replan). Same status-clear as
     * requeueReviewMaps, plus the drift/seats_seated reset. A gate-refused
     * header only moves when its data changes, so it is left in place.
     *
     * @param  array<int,string>|null  $legislatureIds  null = every drifted done map
     * @return array{ok:bool, requeued?:int, error?:string}
     */
    public function requeueDriftMaps(?array $legislatureIds = null): array
    {
        $run = AutoscaleRun::unfinished();
        if ($run === null) {
            return ['ok' => false, 'error' => 'No active autoscale run.'];
        }

        $ids = DB::table('apportionment_ledger')
            ->where('map_status', 'done')
            ->whereNotNull('drift')->where('drift', '<>', 0)
            ->whereNull('gate_reason')
            ->when($legislatureIds !== null, fn ($q) => $q->whereIn('legislature_id', $legislatureIds))
            ->pluck('legislature_id');

        $n = 0;
        foreach ($ids->chunk(5000) as $chunk) {
            DB::table('apportionment_ledger_scopes')
                ->whereIn('legislature_id', $chunk)
                ->update([
                    'status' => 'pending', 'claim_token' => null, 'reason' => null,
                    'started_at' => null, 'finished_at' => null,
                    'retry_count' => 0, 'updated_at' => now(),
                ]);
            $n += DB::table('apportionment_ledger')
                ->whereIn('legislature_id', $chunk)
                ->update([
                    // redraw_requested_at is LOAD-BEARING: it makes the sweep's
                    // adopt-existing-map guard skip and redraw through the
                    // audited replace path (SweepScopeProcessor). Without it a
                    // requeued done map is ADOPTED (its old drifted districts
                    // kept) instead of redrawn. The processor clears the flag
                    // after the redraw, so the bypass is one-shot.
                    'map_status'          => 'pending', 'reason' => null, 'claim_token' => null,
                    'redraw_requested_at' => now(), 'priority_at' => now(),
                    'drift'               => null, 'seats_seated' => null,
                    'started_at'          => null, 'finished_at' => null,
                    'updated_at'          => now(),
                ]);
        }
        Artisan::call('autoscale:pump');

        return ['ok' => true, 'requeued' => $n];
    }

    /**
     * Recompute a completed map's seated total and drift from its CURRENT
     * active districts (operator order 2026-09-03: "a recheck button on the
     * drift maps ... I can make changes in the map manually ... close the loop
     * on the residuals"). Same formula as the finalize: (seats − bonus_seats)
     * against the legislature's type_a_seats. A map an operator has hand-fixed
     * to sum exactly recomputes to drift 0 and drops off the drift list. No
     * redraw, so the operator's manual work is preserved.
     *
     * @param  array<int,string>|null  $legislatureIds  null = every drifted map
     * @return array{ok:bool, rechecked:int, cleared:int, results:array}
     */
    public function recheckDrift(?array $legislatureIds = null): array
    {
        $headers = DB::table('apportionment_ledger')
            ->where('map_status', 'done')
            ->when(
                $legislatureIds !== null,
                fn ($q) => $q->whereIn('legislature_id', $legislatureIds),
                fn ($q) => $q->whereNotNull('drift')->where('drift', '<>', 0),
            )
            ->pluck('legislature_id');

        $results = [];
        $cleared = 0;
        foreach ($headers as $legislatureId) {
            $adopted = DB::table('legislature_district_maps')
                ->where('legislature_id', $legislatureId)
                ->where('status', 'active')->whereNull('deleted_at')
                ->orderByDesc('created_at')->first(['id']);
            if ($adopted === null) {
                continue; // no active map to measure — leave it for a redraw
            }
            $agg = DB::table('legislature_districts')
                ->where('map_id', $adopted->id)->whereNull('deleted_at')
                ->selectRaw('COALESCE(SUM(seats),0) AS s, COALESCE(SUM(bonus_seats),0) AS b')
                ->first();
            $expected = (int) DB::table('legislatures')
                ->where('id', $legislatureId)->value('type_a_seats');
            $seated = (int) $agg->s;
            $drift  = ($seated - (int) $agg->b) - $expected;   // net of lawful bonus lifts

            DB::table('apportionment_ledger')
                ->where('legislature_id', $legislatureId)
                ->where('map_status', 'done')
                ->update(['seats_seated' => $seated, 'drift' => $drift, 'updated_at' => now()]);

            if ($drift === 0) {
                $cleared++;
            }
            $results[] = ['legislature_id' => (string) $legislatureId, 'seated' => $seated, 'drift' => $drift];
        }

        return ['ok' => true, 'rechecked' => count($results), 'cleared' => $cleared, 'results' => $results];
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
    public function killLease(string $leaseId, string $reasonPrefix, ?int $limitMinutes = null, bool $shuntToBox = false): ?array
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
        // GRIND SHUNT (operator order 2026-09-03): the backend is dead; now
        // requeue the stuck scope to REDRAW AS A BOX instead of parking it in
        // review — the box completes the scope where shortest ground (proven
        // on Tumaco: box draws in ~4 s). Loop guard: a scope that grinds AGAIN
        // while force_box is ALREADY set (the box itself could not finish)
        // falls through to the normal review park below.
        $shunted = [];
        if ($shuntToBox && $current !== null) {
            $shunted = DB::select("
                UPDATE apportionment_ledger_scopes
                   SET status = 'pending', force_box = true, claim_token = NULL,
                       started_at = NULL, finished_at = NULL, reason = ?, updated_at = now()
                 WHERE id = ?::uuid AND claim_token = ?::uuid AND status = 'running'
                   AND force_box = false
             RETURNING id, legislature_id
            ", [$reason.' — redraw as box', (string) $current, $leaseId]);
        }

        // Not shunted (a normal kill, or the loop guard fired): the scope in
        // hand parks in review with the kill reason.
        $parked = ($current === null || $shunted !== []) ? [] : DB::select("
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
            'parked' => count($parked), 'shunted' => count($shunted),
            'released' => $released, 'headers_handed' => $handed,
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

    /**
     * THE GRIND SHUNT (operator order 2026-09-03). A leaf whose blade search
     * grinds on an uninterruptible PostGIS raster query cannot be stopped by
     * the in-process wall cap or statement_timeout — only a backend terminate
     * stops it (proven on Tumaco: pg_terminate_backend killed the stuck query
     * in 1 s). This per-tick sweep terminates such a lane and requeues its
     * scope to REDRAW AS A BOX (killLease shuntToBox), so the scope completes
     * (~4 s on Tumaco) instead of hanging or parking in review. The limit is
     * SECONDS (grind_box_seconds), tighter than the minutes-based auto_kill,
     * and generous enough to clear a legitimate slow load. Runs BEFORE
     * sweepKills so a shunt-eligible lane shunts rather than parks. Returns the
     * number of lanes shunted.
     */
    public function sweepGrindShunts(AutoscaleRun $run): int
    {
        if (! self::laneControlColumnsPresent()
            || ! \Illuminate\Support\Facades\Schema::hasColumn('apportionment_ledger_scopes', 'force_box')) {
            return 0;
        }

        $seconds = (int) config('cga.districting.grind_box_seconds', 120);
        if ($seconds <= 0) {
            return 0;
        }

        $shunted = 0;
        $overdue = DB::table('autoscale_worker_leases')
            ->where('run_id', $run->id)
            ->whereIn('claim_type', ['scope', 'scope_batch'])
            ->whereNotNull('claim_started_at')
            ->where('claim_started_at', '<', now()->subSeconds($seconds))
            ->pluck('id');
        foreach ($overdue as $leaseId) {
            $res = $this->killLease((string) $leaseId, 'grind shunt', null, true);
            if ($res !== null && (int) ($res['shunted'] ?? 0) > 0) {
                $shunted++;
            }
        }

        return $shunted;
    }

    /**
     * FINALIZE-ORPHAN RECOVERY (operator order 2026-09-04): a sweep header can
     * be left at map_status = pending with every scope already closed and
     * finalize_ready = false — a requeue or reclaim reset the header while no
     * further scope close ran to arm it. The finalize claimer only picks
     * running + finalize_ready headers, so such a header never finalizes: its
     * map is complete and seated, yet the header (and its layer bar's done
     * count, and the ✓) never closes, and its scopes read as not-done. This
     * per-tick sweep re-arms them to running + finalize_ready so the finalize
     * lane assesses and closes them the normal way. Guarded on ALL scopes
     * being closed (a fresh pending header still has open scopes and is
     * skipped) and on at least one scope existing. Returns the number
     * re-armed. Cheap: the anti-join is index probes on
     * (legislature_id, status), and only orphans (usually none) are written.
     */
    public function sweepFinalizeOrphans(AutoscaleRun $run): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('apportionment_ledger', 'finalize_ready')) {
            return 0;
        }

        return DB::update("
            UPDATE apportionment_ledger h
               SET map_status = 'running', finalize_ready = true, updated_at = now()
             WHERE h.kind = 'sweep'
               AND h.map_status = 'pending'
               AND EXISTS (SELECT 1 FROM apportionment_ledger_scopes s
                            WHERE s.legislature_id = h.legislature_id)
               AND NOT EXISTS (SELECT 1 FROM apportionment_ledger_scopes s
                                WHERE s.legislature_id = h.legislature_id
                                  AND s.status IN ('pending', 'running'))
        ");
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
