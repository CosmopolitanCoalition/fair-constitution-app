<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G4 write-routing) — the LEADER-side idempotency ledger for forwarded
 * writes. A write for a jurisdiction we don't own is forwarded to its
 * authoritative leader and executed through the NORMAL ConstitutionalEngine
 * (no bypass); this table makes that execution exactly-once.
 *
 * The (origin_server_id, idempotency_key) unique index means a network retry of
 * the same logical write re-reads the recorded outcome instead of re-filing —
 * the engine's audit chain is never double-appended. It stores only the OUTCOME
 * (audit seq + hash, or a rejection citation) — never ballots, locations, or
 * credentials (those never leave their authoritative instance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forwarded_writes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The instance that forwarded the write to us, and its per-write key.
            $table->uuid('origin_server_id');
            $table->string('idempotency_key', 128);

            $table->string('form_id', 64);
            $table->uuid('jurisdiction_id')->nullable();

            // pending (claimed, executing) | executed | rejected
            $table->string('status', 12)->default('pending');

            // Proof of execution — the audit row this forwarded write sealed.
            $table->bigInteger('audit_seq')->nullable();
            $table->string('result_hash', 128)->nullable();

            // On a constitutional rejection, the citation the engine recorded.
            $table->string('citation')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('jurisdiction_id');
            $table->index('origin_server_id');
        });

        DB::statement('ALTER TABLE forwarded_writes ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE forwarded_writes ADD CONSTRAINT forwarded_writes_status_check "
          ."CHECK (status IN ('pending','executed','rejected'))"
        );

        // Exactly-once: one row per (origin, key). A concurrent duplicate forward
        // fails this index → the controller maps it to 409 (in flight); a later
        // retry re-reads the settled row.
        DB::statement(
            'CREATE UNIQUE INDEX forwarded_writes_origin_key_unique '
          .'ON forwarded_writes (origin_server_id, idempotency_key) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('forwarded_writes');
    }
};
