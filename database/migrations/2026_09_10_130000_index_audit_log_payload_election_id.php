<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The election page took 14 s in one query (box E, 2026-09-10):
 *   SELECT * FROM audit_log WHERE module = ? AND event = ? AND payload->>'election_id' = ?
 *   ORDER BY seq ASC LIMIT 1
 * — a full scan of the chain for the F-ELB-001 scheduling order. This
 * expression index answers it from the index (not partial: the planner
 * cannot prove payload ? 'election_id' from the ->> equality). Built
 * CONCURRENTLY, outside
 * the migration transaction, so a live box keeps appending while it builds.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS audit_log_module_event_payload_election_idx
    ON audit_log (module, event, ((payload->>'election_id')))
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS audit_log_module_event_payload_election_idx');
    }
};
