<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G-OP) — the FEDERATED trust material: device-public-key bindings. A
 * mesh operator is DEFINED by the set of device public keys bound to its
 * mesh_operator_id, each binding signed by an instance the mesh trusts (a pinned
 * FederationPeer, or self). Verifying "is this action from operator X?" =
 * checking the action signature against a key whose binding to X is signed by a
 * trusted instance — mirrors the directory's "each entry signed by the server it
 * names" pattern. No secret is stored; revocation rides a signed CRL like the
 * attestation layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mesh_operator_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('mesh_operator_id');
            $table->text('device_public_key');           // one of the operator's enrolled device public keys
            $table->uuid('bound_by_server_id');          // which instance asserts this binding
            $table->text('binding_signature');           // Ed25519 sig by bound_by_server_id over the canonical binding
            $table->string('status', 16)->default('active'); // active | revoked
            $table->timestampTz('bound_at');
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('mesh_operator_id');
        });

        DB::statement('ALTER TABLE mesh_operator_keys ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            'ALTER TABLE mesh_operator_keys ADD CONSTRAINT mesh_operator_keys_status_check '
          ."CHECK (status IN ('active','revoked'))"
        );
        // One binding per (identity, device key).
        DB::statement(
            'CREATE UNIQUE INDEX mesh_operator_keys_identity_key_unique '
          .'ON mesh_operator_keys (mesh_operator_id, device_public_key) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('mesh_operator_keys');
    }
};
