<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G9 directory) — an ADVISORY, signed, replicable `jurisdiction → best
 * endpoints` lookup. It answers "where might I reach the instance that serves
 * jurisdiction X?" so a write can be FORWARDED there (G4). It holds NO AUTHORITY:
 * authority lives only in `jurisdictions.authoritative_server_id`; a directory
 * entry is a routing hint anyone may publish and anyone may ignore. Each entry is
 * signed by the SERVER it names, so a relayed entry is self-authenticating and a
 * tampered one is rejected on ingest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jurisdiction_id');
            $table->uuid('server_id'); // the instance that claims to serve it (the publisher)

            // [{transport: 'https'|'tailnet'|'onion'|'sneakernet', url: '...'}]
            $table->jsonb('endpoints');
            $table->integer('priority')->default(100);

            // The publisher's Ed25519 signature over the canonical entry.
            $table->text('signature')->nullable();

            // Who relayed it to us (NULL = we published it ourselves).
            $table->uuid('source_server_id')->nullable();

            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('expires_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('jurisdiction_id');
            $table->index('server_id');
        });

        DB::statement('ALTER TABLE directory_entries ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // One entry per (jurisdiction, publishing server, relay source).
        DB::statement(
            'CREATE UNIQUE INDEX directory_entries_jurisdiction_server_source_unique '
          .'ON directory_entries (jurisdiction_id, server_id, COALESCE(source_server_id, server_id)) '
          .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_entries');
    }
};
