<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LANE KILL CONTROLS (operator order 2026-09-02): deadlines are WARNINGS,
 * kills are manual or opt-in automatic. Three columns carry the controls:
 *
 *  - autoscale_worker_leases.kill_requested_at: the operator's kill button
 *    stamps it; the controller kills at once and the pump kills every
 *    stamped lease on its next minute as the backstop.
 *  - autoscale_worker_leases.current_scope_id: the ONE scope the lane is
 *    working right now (a scope claim's scope, or the batch scope in hand).
 *    A kill parks exactly this scope; a batch's untouched remainder returns
 *    to pending. The lane writes it before each scope together with
 *    claim_started_at, so a deadline measures the scope in hand, never the
 *    whole batch.
 *  - autoscale_runs.auto_kill_minutes: NULL = no automatic kill. When set,
 *    the pump kills every scope / scope_batch claim whose scope in hand is
 *    older than this many minutes. Singles claims are never auto-killed.
 *
 * A killed scope PARKS in review. No retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE autoscale_worker_leases ADD COLUMN IF NOT EXISTS kill_requested_at timestamptz');
        DB::statement('ALTER TABLE autoscale_worker_leases ADD COLUMN IF NOT EXISTS current_scope_id uuid');
        DB::statement('ALTER TABLE autoscale_runs ADD COLUMN IF NOT EXISTS auto_kill_minutes smallint');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE autoscale_worker_leases DROP COLUMN IF EXISTS kill_requested_at');
        DB::statement('ALTER TABLE autoscale_worker_leases DROP COLUMN IF EXISTS current_scope_id');
        DB::statement('ALTER TABLE autoscale_runs DROP COLUMN IF EXISTS auto_kill_minutes');
    }
};
