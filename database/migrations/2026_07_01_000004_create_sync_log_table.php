<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F (WF-JUR-06) — APPEND-ONLY ledger of every federation sync exchange.
 *
 * One row per inbound/outbound Full-Faith-&-Credit transfer. Like `audit_log`
 * this is immutable by construction (a sync that "didn't happen" cannot be
 * un-logged): no soft delete, no updated_at, and a BEFORE UPDATE OR DELETE
 * trigger + a TRUNCATE block at the database layer.
 *
 * `result` records the verification outcome:
 *   applied                     — peer chain verified, records ingested.
 *   conflict_authoritative_wins — record(s) for a jurisdiction WE are
 *                                 authoritative for; our copy kept, peer's noted.
 *   rejected_tamper             — peer signature or chain recompute failed;
 *                                 nothing applied.
 *   rejected_non_authoritative  — peer shipped records it is not authoritative
 *                                 for; refused.
 *
 * CONVENTION EXCEPTIONS (deliberate — append-only, mirrors audit_log):
 *   no soft delete · no updated_at · DB immutability triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_log', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // No FK — immutability over cascade (audit_log posture). References
            // federation_peers.id; null for system/self entries.
            $table->uuid('peer_id')->nullable();

            $table->string('direction', 8);

            // sha256 of the canonical synced payload (tail/bundle digest).
            $table->char('payload_hash', 64);

            // The peer's claimed audit head hash for this exchange.
            $table->char('peer_head_hash', 64)->nullable();

            // The audit-seq window shipped/received.
            $table->bigInteger('from_seq')->nullable();
            $table->bigInteger('to_seq')->nullable();

            $table->string('result', 32);

            // Link to the audit_log row that recorded this sync event.
            $table->bigInteger('audit_seq')->nullable();

            // Counts of records applied/skipped, the conflict list, etc.
            $table->jsonb('detail')->default('{}');

            $table->timestampTz('created_at')->useCurrent();

            $table->index('peer_id');
            $table->index('result');
            $table->index('direction');
        });

        DB::statement('ALTER TABLE sync_log ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // Chain order. BIGSERIAL (not the uuid PK) so the ledger walks in
        // insertion order; gaps from rolled-back inserts are harmless.
        DB::statement('ALTER TABLE sync_log ADD COLUMN seq BIGSERIAL');
        DB::statement('ALTER TABLE sync_log ADD CONSTRAINT sync_log_seq_unique UNIQUE (seq)');

        DB::statement("ALTER TABLE sync_log ADD CONSTRAINT sync_log_direction_check CHECK (direction IN ('inbound','outbound'))");
        DB::statement("
            ALTER TABLE sync_log ADD CONSTRAINT sync_log_result_check
            CHECK (result IN ('applied','conflict_authoritative_wins','rejected_tamper','rejected_non_authoritative'))
        ");

        // ── Append-only enforcement at the database layer ───────────────────
        DB::statement("
            CREATE OR REPLACE FUNCTION sync_log_block_mutation()
            RETURNS TRIGGER AS \$\$
            BEGIN
                RAISE EXCEPTION 'sync_log is append-only: % is not permitted', TG_OP;
            END;
            \$\$ LANGUAGE plpgsql;
        ");
        DB::statement('
            CREATE TRIGGER sync_log_immutable
            BEFORE UPDATE OR DELETE ON sync_log
            FOR EACH ROW EXECUTE FUNCTION sync_log_block_mutation();
        ');
        DB::statement('
            CREATE TRIGGER sync_log_no_truncate
            BEFORE TRUNCATE ON sync_log
            FOR EACH STATEMENT EXECUTE FUNCTION sync_log_block_mutation();
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS sync_log_immutable ON sync_log');
        DB::statement('DROP TRIGGER IF EXISTS sync_log_no_truncate ON sync_log');
        Schema::dropIfExists('sync_log');
        DB::statement('DROP FUNCTION IF EXISTS sync_log_block_mutation');
    }
};
