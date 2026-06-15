<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G-ID) — a short-lived, revocable, instance-signed attestation of a
 * person's DERIVED standing (their role codes at a moment), bound to a device
 * signing key. It lets a node that does NOT hold the person's residency facts
 * authorize a write they signed — WITHOUT replicating credentials/ballots/
 * locations across the privacy boundary.
 *
 * Privacy boundary (pinned by AttestationIntegrityTest): this table carries ONLY
 * derived, public standing — role codes + a device public key + issuer + TTL +
 * signature. NO password/credential, NO ballot content, NO raw location. Signed
 * with the existing INSTANCE Ed25519 key (no second PKI); a peer verifies against
 * the issuer's pinned `federation_peers.public_key`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standing_attestations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The person + the device key this attestation binds (no FK cascade —
            // a verifier may be a peer that does not hold the subject row).
            $table->uuid('subject_user_id');
            $table->text('device_public_key');

            // Who attested (their federation server_id).
            $table->uuid('issuer_server_id');

            // The SNAPSHOTTED derived role codes (e.g. ["R-01","R-03"]). Derived,
            // public standing — never credentials/locations/ballots.
            $table->jsonb('roles')->default('[]');

            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at');

            // Detached Ed25519 signature (base64) over the canonical form.
            $table->text('signature');

            // Federation convention: NULL = ours; set = mirrored from this peer.
            $table->uuid('source_server_id')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('subject_user_id');
            $table->index('issuer_server_id');
            $table->index('expires_at');
        });

        DB::statement('ALTER TABLE standing_attestations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('standing_attestations');
    }
};
