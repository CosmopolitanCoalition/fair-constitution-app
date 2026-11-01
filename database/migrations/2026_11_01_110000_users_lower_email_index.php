<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LOGIN ON LARGE USER TABLES (operator order 2026-11-01).
 *
 * The legacy-casing login fallback reads `lower(email) = ?`. The only email
 * index was on the plain column, so that lookup scanned every row (18.9 million
 * users, 12 GB on the demo, 30 to 46 seconds on a spinning disk). This index
 * serves it.
 *
 * CONCURRENTLY: no write lock on `users`, so logins and signups continue during
 * the build. That statement cannot run inside a transaction. IF NOT EXISTS makes
 * it a no-op when an operator already built the index by hand on a large box.
 * Additive, REAL-dated.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS users_lower_email_idx ON users (lower(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS users_lower_email_idx');
    }
};
