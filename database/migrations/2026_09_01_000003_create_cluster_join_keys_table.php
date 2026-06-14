<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G2) — a cluster join key. A host mints a secret a would-be mirror
 * presents at `POST /api/federation/adopt` to be admitted in one step.
 *
 * The plaintext (`handle.secret`) is shown to the operator ONCE; only the Argon2id
 * `key_hash` is stored and only the public `handle` is ever audited. A key is live
 * while not revoked, not expired, and `uses < max_uses` — consumption is atomic
 * (SELECT … FOR UPDATE) so a one-use key admits exactly one mirror even under a
 * concurrent race.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_join_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The public, auditable short id (the part before the '.').
            $table->string('handle', 16);

            // Argon2id hash of the secret — the plaintext is never stored.
            $table->text('key_hash');

            $table->integer('max_uses')->default(1);
            $table->integer('uses')->default(0);

            // An optional subtree this key admits a mirror for (NULL = whole corpus).
            $table->uuid('scope_jurisdiction_id')->nullable();

            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE cluster_join_keys ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE cluster_join_keys ADD CONSTRAINT cluster_join_keys_max_uses_check CHECK (max_uses >= 1)');
        DB::statement('ALTER TABLE cluster_join_keys ADD CONSTRAINT cluster_join_keys_uses_check CHECK (uses >= 0)');

        // One live key per handle.
        DB::statement(
            'CREATE UNIQUE INDEX cluster_join_keys_handle_unique '
          .'ON cluster_join_keys (handle) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_join_keys');
    }
};
