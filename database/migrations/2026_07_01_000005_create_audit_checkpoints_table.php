<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F (WF-JUR-06) — APPEND-ONLY signed checkpoints of our audit head.
 *
 * A checkpoint pins (audit_seq, head_hash) at a moment in time and signs it, so
 * a peer can verify that a tail or partition bundle we ship genuinely descends
 * from a head WE attested to. `published_to` records which peer server_ids the
 * checkpoint was broadcast to.
 *
 * Immutable by construction (same posture as audit_log / sync_log).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_checkpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The audit_log.seq this checkpoint pins (our head at checkpoint time).
            $table->bigInteger('audit_seq');

            // audit_log.hash at audit_seq — the tamper-evident anchor.
            $table->char('head_hash', 64);

            // Peer server_ids this checkpoint was published to.
            $table->jsonb('published_to')->default('[]');

            // Our Ed25519 signature over head_hash || audit_seq.
            $table->text('signature');

            $table->timestampTz('created_at')->useCurrent();

            $table->index('audit_seq');
        });

        DB::statement('ALTER TABLE audit_checkpoints ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement('ALTER TABLE audit_checkpoints ADD COLUMN seq BIGSERIAL');
        DB::statement('ALTER TABLE audit_checkpoints ADD CONSTRAINT audit_checkpoints_seq_unique UNIQUE (seq)');

        // ── Append-only enforcement at the database layer ───────────────────
        DB::statement("
            CREATE OR REPLACE FUNCTION audit_checkpoints_block_mutation()
            RETURNS TRIGGER AS \$\$
            BEGIN
                RAISE EXCEPTION 'audit_checkpoints is append-only: % is not permitted', TG_OP;
            END;
            \$\$ LANGUAGE plpgsql;
        ");
        DB::statement('
            CREATE TRIGGER audit_checkpoints_immutable
            BEFORE UPDATE OR DELETE ON audit_checkpoints
            FOR EACH ROW EXECUTE FUNCTION audit_checkpoints_block_mutation();
        ');
        DB::statement('
            CREATE TRIGGER audit_checkpoints_no_truncate
            BEFORE TRUNCATE ON audit_checkpoints
            FOR EACH STATEMENT EXECUTE FUNCTION audit_checkpoints_block_mutation();
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS audit_checkpoints_immutable ON audit_checkpoints');
        DB::statement('DROP TRIGGER IF EXISTS audit_checkpoints_no_truncate ON audit_checkpoints');
        Schema::dropIfExists('audit_checkpoints');
        DB::statement('DROP FUNCTION IF EXISTS audit_checkpoints_block_mutation');
    }
};
