<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F (WF-JUR-06) — a peer instance in the federation mesh.
 *
 * One row per known peer. Lifecycle (ESM-20):
 *   discovered → handshake → trust_established → (syncing / conflict_resolution
 *   / border_settled / merged / departed). The `public_key` is captured at
 *   handshake (trust-on-first-use) and every subsequent peer request is verified
 *   against it (VerifyPeerSignature middleware).
 *
 * Watermarks make sync incremental:
 *   `last_synced_seq` — highest of OUR audit seq shipped TO this peer (outbound).
 *   `peer_head_seq`   — highest peer audit seq we have INGESTED (inbound).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federation_peers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The peer's federation identity (their instance_settings.server_id).
            $table->uuid('server_id');

            $table->string('name')->nullable();
            $table->string('url');

            // Ed25519 public key (base64), pinned at handshake.
            $table->text('public_key')->nullable();

            $table->string('status', 24)->default('discovered');

            // Handshake metadata: cosmic_address, schema_version, software.
            $table->jsonb('metadata')->default('{}');

            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->timestampTz('trust_established_at')->nullable();

            // Sync watermarks (see docblock).
            $table->bigInteger('last_synced_seq')->nullable();
            $table->bigInteger('peer_head_seq')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
        });

        DB::statement('ALTER TABLE federation_peers ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // One active peer row per server_id.
        DB::statement(
            'CREATE UNIQUE INDEX federation_peers_server_id_unique '
          . 'ON federation_peers (server_id) WHERE deleted_at IS NULL'
        );

        DB::statement("
            ALTER TABLE federation_peers ADD CONSTRAINT federation_peers_status_check
            CHECK (status IN ('discovered','handshake','trust_established','syncing',
                              'conflict_resolution','border_settled','merged','departed'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_peers');
    }
};
