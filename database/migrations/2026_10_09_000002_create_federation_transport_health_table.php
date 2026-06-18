<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G8b) — per-(server, transport, url) reachability bookkeeping for the
 * multiplex survival mesh. The multiplex tries a peer's transports best-first until
 * one SURVIVES; this table is how it remembers which channels are up, how fast, and
 * which have tripped their circuit (so a known-dead endpoint is skipped fast instead
 * of paying a fresh timeout on every call).
 *
 * The key matches the ladder's dedupe key (transport, url) — a peer can be reachable
 * over the SAME transport at more than one url (a learned address + a directory
 * endpoint + the legacy url), and each url owns its own circuit so a dead address
 * never shadows a healthy sibling on the same transport.
 *
 * OPERATIONAL state, NOT constitutional — explicitly outside the CLK registry and
 * the hardened layer (it gates no filing, decides no authority; mirrors the
 * advisory `last_heartbeat_at` already on federation_peers). Additive table; no
 * protected migration touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federation_transport_health', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('server_id');         // whose endpoint's health this is
            $table->string('transport', 16);   // https | tailnet | onion | sneakernet | yggdrasil
            $table->text('url');               // the specific reachable address (the dial target)

            $table->timestampTz('last_ok_at')->nullable();
            $table->timestampTz('last_fail_at')->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->integer('latency_ema_ms')->nullable(); // exponential moving avg of observed latency
            // closed | open ; 'half_open' (a cooled-open circuit eligible for one probe)
            // is a DERIVED ladder state, kept in the CHECK for forward-compat.
            $table->string('circuit_state', 12)->default('closed');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('server_id');
        });

        DB::statement('ALTER TABLE federation_transport_health ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            'ALTER TABLE federation_transport_health ADD CONSTRAINT federation_transport_health_circuit_check '
          ."CHECK (circuit_state IN ('closed','open','half_open'))"
        );

        // One health row per (server, transport, url) — the ladder's dedupe key.
        DB::statement(
            'CREATE UNIQUE INDEX federation_transport_health_server_transport_url_unique '
          .'ON federation_transport_health (server_id, transport, url) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_transport_health');
    }
};
