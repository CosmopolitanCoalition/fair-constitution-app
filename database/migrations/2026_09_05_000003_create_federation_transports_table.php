<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G8 transport) — the channels an instance is reachable over. The SAME
 * signed federation bytes travel over https, a Headscale tailnet, a Tor .onion, or
 * sneakernet; a transport row records ONE such reachable address. Our own rows
 * (is_self) are what we publish into the G9 directory as a jurisdiction's endpoints;
 * the FederationClient SOCKS seam decides how to dial each one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federation_transports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('server_id'); // whose transport (is_self = ours)
            $table->string('transport', 16); // https | tailnet | onion | sneakernet
            $table->text('address');          // the reachable URL/address for this transport

            $table->boolean('is_self')->default(false);
            $table->integer('priority')->default(100);
            $table->boolean('enabled')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('server_id');
        });

        DB::statement('ALTER TABLE federation_transports ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE federation_transports ADD CONSTRAINT federation_transports_transport_check "
          ."CHECK (transport IN ('https','tailnet','onion','sneakernet'))"
        );

        // One row per (server, transport).
        DB::statement(
            'CREATE UNIQUE INDEX federation_transports_server_transport_unique '
          .'ON federation_transports (server_id, transport) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_transports');
    }
};
