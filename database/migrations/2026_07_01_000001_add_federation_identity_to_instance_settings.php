<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F (WF-JUR-06 federation) — this instance's stable cross-mesh identity.
 *
 * Federation is "authoritative-instance-wins" over a peer mesh; an instance must
 * therefore carry ONE durable identity that survives `migrate` and re-seeds:
 *  - `server_id`            — the UUID a peer addresses us by (NULL until
 *                            `federation:init` mints it; lives on the singleton).
 *  - `public_key` /
 *    `private_key_encrypted`— an Ed25519 keypair. The public half is shared at
 *                            handshake (TOFU-pinned by peers); the private half
 *                            is encrypted at rest with Laravel Crypt (APP_KEY)
 *                            and used by InstanceIdentityService::sign().
 *  - `federation_enabled`   — operator opt-in gate. The `/api/federation/*`
 *                            machine endpoints 404 while false (off is
 *                            indistinguishable from absent, like dev-tools).
 *
 * SECURITY: `private_key_encrypted` must never leave this instance. Partition
 * exports (the federation path) dump only a jurisdiction subtree, never
 * `instance_settings`, so the secret cannot ride a peer bundle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->uuid('server_id')->nullable()
                ->comment('Phase F: stable federation identity; NULL until federation:init mints it');
            $table->text('public_key')->nullable()
                ->comment('Ed25519 public key (base64), shared at handshake');
            $table->text('private_key_encrypted')->nullable()
                ->comment('Ed25519 secret key, Crypt-encrypted at rest; never exported');
            $table->timestampTz('signing_key_generated_at')->nullable();
            $table->boolean('federation_enabled')->default(false)
                ->comment('Operator gate for the /api/federation/* mesh endpoints');
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'server_id',
                'public_key',
                'private_key_encrypted',
                'signing_key_generated_at',
                'federation_enabled',
            ]);
        });
    }
};
