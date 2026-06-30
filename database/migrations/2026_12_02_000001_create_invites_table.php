<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Person-to-person INVITES — the growth primitive. A member mints a shareable link
 * to a destination they can already reach (the public square, a hall, a live call, a
 * public proceeding); a friend opens it, lands in the app, and — after signing up —
 * continues straight to that destination. Attribution is recorded on consume.
 *
 * Security posture mirrors the federation join-key (`cluster_join_keys`), but this is a
 * SEPARATE concern (people, not nodes):
 *   - the plaintext (`handle.secret`) is shown to the inviter ONCE; only the Argon2id
 *     `token_hash` is stored, and only the public `handle` is ever logged;
 *   - verification is constant-time; consumption is atomic (SELECT … FOR UPDATE);
 *   - `destination` is an INTERNAL, server-built ref (kind + a same-origin app path) —
 *     never an arbitrary URL, so a link can't be turned into an open redirect;
 *   - an invite grants ACCESS to an already-open destination (Art. I) — never residency,
 *     a role, or any governance power.
 *
 * `max_uses` NULL = a reusable link (the default — "join my call" works for several
 * friends); a positive value caps redemptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invites', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The public, auditable short id (the part before the '.').
            $table->string('handle', 16);

            // Argon2id hash of the secret — the plaintext is never stored.
            $table->text('token_hash');

            // Who minted it (attribution / "X invited you"). Kept if the inviter is later removed.
            $table->uuid('inviter_user_id')->nullable();

            // call | commons | proceeding | space — drives the landing label + the path builder.
            $table->string('kind', 32);

            // Internal destination ref: { path, jurisdiction_id?, space?, … } — built server-side.
            $table->jsonb('destination');

            // Human label shown on the guest landing ("United Earth — Public Square").
            $table->string('label', 160)->nullable();

            // NULL = reusable; a positive integer caps redemptions.
            $table->integer('max_uses')->nullable();
            $table->integer('uses')->default(0);

            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('inviter_user_id');
        });

        DB::statement('ALTER TABLE invites ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE invites ADD CONSTRAINT invites_max_uses_check CHECK (max_uses IS NULL OR max_uses >= 1)');
        DB::statement('ALTER TABLE invites ADD CONSTRAINT invites_uses_check CHECK (uses >= 0)');

        // The inviter is a soft reference — keep the invite (for the record) if the user row is removed.
        DB::statement(
            'ALTER TABLE invites ADD CONSTRAINT invites_inviter_user_id_foreign '
          .'FOREIGN KEY (inviter_user_id) REFERENCES users (id) ON DELETE SET NULL'
        );

        // One live invite per handle.
        DB::statement(
            'CREATE UNIQUE INDEX invites_handle_unique '
          .'ON invites (handle) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('invites');
    }
};
