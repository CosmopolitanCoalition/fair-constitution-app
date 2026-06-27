<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 / K-3 (K3-C.2) — short-lived OIDC authorization codes for the game-as-OIDC-provider.
 *
 * A code is issued by /oauth/authorize after the citizen authenticates, and redeemed ONCE at /oauth/token
 * for an id_token. We store only the SHA-256 HASH of the code (never the raw value — a DB read can't replay
 * a code), bound to the client, the exact redirect_uri, the PKCE challenge, the OIDC nonce, and the user.
 * Single-use is enforced by a conditional UPDATE of consumed_at; expiry is seconds-scale. Ephemeral infra
 * rows (not a civic record) — pruned after expiry, no soft-delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_authorization_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code_hash', 64);                 // sha256(raw code) hex — the raw code is never stored
            $table->string('client_id', 128);
            $table->uuid('user_id');                         // the authenticated game user (soft ref users.id)
            $table->text('redirect_uri');                    // bound + re-checked at redemption (exact match)
            $table->string('scope', 255)->default('openid');
            $table->string('code_challenge', 255);           // PKCE S256 challenge (base64url) — mandatory
            $table->string('nonce', 255)->nullable();        // OIDC nonce, passed through into the id_token
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();  // single-use marker (conditional-UPDATE guarded)
            $table->timestampsTz();
            $table->index('user_id');
            $table->index('expires_at');
        });

        DB::statement('ALTER TABLE oidc_authorization_codes ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            'CREATE UNIQUE INDEX oidc_authorization_codes_hash_unique ON oidc_authorization_codes (code_hash)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_authorization_codes');
    }
};
