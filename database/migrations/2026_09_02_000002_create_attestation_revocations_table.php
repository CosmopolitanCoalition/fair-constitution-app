<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G-ID) — the attestation CRL. A signed revocation kills a standing
 * attestation before its TTL (relocation, lost device, lost standing). It
 * federates the same way an attestation does (source_server_id convention) so a
 * mirror/peer converges fast. The issuer signs it with the INSTANCE key, so any
 * verifier can confirm only the genuine issuer revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attestation_revocations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The revoked attestation (no FK cascade — a CRL entry stands alone and
            // federates independently of the attestation row).
            $table->uuid('attestation_id');
            $table->uuid('issuer_server_id');

            $table->string('reason', 48)->nullable();
            $table->timestampTz('revoked_at');

            $table->text('signature');
            $table->uuid('source_server_id')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('attestation_id');
        });

        DB::statement('ALTER TABLE attestation_revocations ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // One live revocation per attestation.
        DB::statement(
            'CREATE UNIQUE INDEX attestation_revocations_one_per_attestation '
          .'ON attestation_revocations (attestation_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('attestation_revocations');
    }
};
