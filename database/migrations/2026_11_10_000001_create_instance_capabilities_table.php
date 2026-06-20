<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mesh Roles & Channels of Trust (★1) — the capability manifest registry, a structural SIBLING of
 * federation_transports (transports = HOW you reach a box; capabilities = WHAT a box offers). A box's
 * "role" is the derived SET of enabled capability channels it holds, never a stored tier. Governed
 * channels (broker.dns/.tls, client.serve, authority.grant, matrix.homeserver, voice.sfu) may only be
 * enabled with a verified, unexpired GRANT minted by the dual-meter consent (PeerUpgradeAgreementService);
 * self-asserted channels (mesh.member, mirror, etl) need none. Grant receipt lives on the row
 * (granted_by_server_id + grant_signature + grant_expires_at) — revocation is by-expiry (decision E).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_capabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('server_id');           // whose capability (is_self = ours)
            $table->string('capability', 32);    // CHECK IN the closed vocabulary
            $table->boolean('is_self')->default(false);
            $table->boolean('enabled')->default(false); // governed default OFF until granted; self-asserted set true on register
            $table->integer('priority')->default(100);

            // Grant receipt (governed channels only) — the cryptographic proof the mesh approved the claim.
            $table->uuid('granted_by_server_id')->nullable();
            $table->text('grant_signature')->nullable();
            $table->timestampTz('grant_expires_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('server_id');
            $table->index('capability');
        });

        DB::statement('ALTER TABLE instance_capabilities ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE instance_capabilities ADD CONSTRAINT instance_capabilities_capability_check "
          ."CHECK (capability IN ('mesh.member','mirror','etl','broker.dns','broker.tls','client.serve',"
          ."'authority.grant','matrix.homeserver','voice.sfu'))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX instance_capabilities_server_capability_unique '
          .'ON instance_capabilities (server_id, capability) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_capabilities');
    }
};
