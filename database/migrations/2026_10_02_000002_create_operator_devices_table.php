<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G-OP) — an operator's Ed25519 signing devices (the operator-plane
 * analogue of actor_devices on the citizen plane). Only the PUBLIC key is
 * stored; the secret never leaves the device (no escrow). THIS key — not the
 * password — is what federates: a device signs operator actions (upgrade
 * consent, peering approval) and is bound to a mesh identity via
 * mesh_operator_keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('operator_account_id');
            $table->text('device_public_key');          // Ed25519 PUBLIC key (base64); secret never escrowed
            $table->string('label')->nullable();
            $table->timestampTz('enrolled_at');
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('operator_account_id');
        });

        DB::statement('ALTER TABLE operator_devices ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        // A public key enrolls once (per live row).
        DB::statement(
            'CREATE UNIQUE INDEX operator_devices_public_key_unique '
          .'ON operator_devices (device_public_key) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_devices');
    }
};
