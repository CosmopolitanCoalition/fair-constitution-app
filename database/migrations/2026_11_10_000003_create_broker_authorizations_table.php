<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mesh Roles & Channels of Trust (★8) — the broker routing table. The cert-broker's static per-domain
 * `authority_keys` whitelist (config/domains.php) generalizes into a mesh-replicated, signed FACT:
 * "authority A attests that broker B may broker under domain D." Each row is signed by the AUTHORITY and
 * gossiped to pinned peers; a receiver verifies it against the authority's OWN pinned key (never the
 * relayer's) — exactly as MeshOperatorService::ingestAnnounce verifies each key binding. The static
 * whitelist becomes a live, mesh-distributed routing table that both the in-mesh broker and Box C feed
 * into the SAME GrantVerifier.
 *
 * The Cloudflare token never appears here — this carries only public keys + names + signatures, so it
 * rides the public chain like every other signed federation fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_authorizations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('domain', 253);          // the naming root (DNS max label-set length)
            $table->uuid('broker_server_id');        // the box authorized to broker under this domain
            $table->uuid('authority_server_id');     // the attesting authority (authority.grant holder)
            $table->text('authority_pubkey');        // the authority's base64 Ed25519 key (must match its pinned key)
            $table->text('signature');               // the authority's detached signature over the canonical fact

            $table->timestampTz('issued_at');
            $table->timestampTz('revoked_at')->nullable(); // authority-driven revocation (fail-closed at read)

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('domain');
            $table->index('broker_server_id');
        });

        DB::statement('ALTER TABLE broker_authorizations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        // One live attestation per (domain, broker, authority) — re-attesting updates in place.
        DB::statement(
            'CREATE UNIQUE INDEX broker_authorizations_unique ON broker_authorizations '
          .'(domain, broker_server_id, authority_server_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_authorizations');
    }
};
