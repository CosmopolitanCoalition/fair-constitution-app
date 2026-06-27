<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 / K-3 (K3-C) — the GAME's OIDC-provider signing keys.
 *
 * The identity bridge flows GAME → Matrix (operator-ratified 2026-06-27): the game is a small OIDC
 * provider; MAS delegates UPSTREAM to it; one CGA login mints the Matrix + LiveKit sessions. This table
 * holds the asymmetric (RS256) keys the game signs id_tokens with — the PUBLIC half is published at the
 * JWKS endpoint (so MAS verifies an id_token without a shared secret), the PRIVATE half is encrypted at
 * rest with the app key (the SAME discipline as instance_settings.private_key_encrypted; it never leaves
 * the box, never federates, never appears in JWKS). Multiple non-revoked keys may be published at once so
 * a rotation overlaps (sign with the newest active, verify against any published). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_signing_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kid', 255);                       // RFC 7638 JWK thumbprint — stable id MAS pins per key
            $table->string('algorithm', 12)->default('RS256');
            $table->jsonb('public_jwk');                      // {kty,use,alg,kid,n,e} — the ONLY thing JWKS exposes
            $table->text('private_pem_encrypted');            // Crypt(app-key) — never published, never federated
            $table->boolean('is_active')->default(true);      // the current signer; published keys may outlive it (rotation)
            $table->timestampTz('rotated_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('is_active');
        });

        DB::statement('ALTER TABLE oidc_signing_keys ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            'CREATE UNIQUE INDEX oidc_signing_keys_kid_unique ON oidc_signing_keys '
          .'(kid) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_signing_keys');
    }
};
