<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G-ID) — a person's enrolled device signing key. A device holds its own
 * Ed25519 keypair and signs ACTIONS (the person-level sibling of the instance
 * signing a peer message). A standing attestation binds the device's PUBLIC key,
 * so a forwarded write carries: a device-signed action + the attestation proving
 * that device key holds the attested standing.
 *
 * Devices are LOCAL (a person enrols their device on their home instance); only
 * the attestation federates. No key escrow — the secret never leaves the device;
 * losing it means re-verifying at the home jurisdiction and enrolling a fresh key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actor_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // The device's Ed25519 PUBLIC key (base64) — the secret stays on-device.
            $table->text('device_public_key');

            $table->string('label')->nullable();
            $table->timestampTz('enrolled_at');
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('user_id');
        });

        DB::statement('ALTER TABLE actor_devices ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // One live enrolment per (user, device key).
        DB::statement(
            'CREATE UNIQUE INDEX actor_devices_user_key_unique '
          .'ON actor_devices (user_id, device_public_key) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('actor_devices');
    }
};
