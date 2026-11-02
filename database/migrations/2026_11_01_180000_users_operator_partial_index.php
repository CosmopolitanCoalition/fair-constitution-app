<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OPERATOR LOOKUP ON LARGE USER TABLES (operator order 2026-11-01).
 *
 * `users where is_operator = true and deleted_at is null limit 1` has no index
 * on `is_operator`, so it scans the table until it finds an operator. On the
 * demo (18.9 million users, one spinning disk) that took 76 seconds per call
 * after a restart. Setup, audit reconcile and the page profiler all read it.
 *
 * A partial index holds only operator rows (a handful), so the lookup is one
 * page read. It is keyed on created_at so the oldest-operator ordering in
 * AuditReconcileCommand is served too.
 *
 * CONCURRENTLY: no write lock on `users`. IF NOT EXISTS makes it a no-op when
 * the index was built by hand on a large box. REAL-dated.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS users_operator_idx ON users (created_at) WHERE is_operator AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS users_operator_idx');
    }
};
