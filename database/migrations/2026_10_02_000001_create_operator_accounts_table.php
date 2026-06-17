<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G-OP, operator mesh identity) — the LOCAL operator login for an
 * instance. This is the OPERATOR plane, deliberately separate from the citizen
 * `users` plane: NO foreign key, no shared identity. A human may be both a
 * citizen and an operator, but the app stores them as unrelated rows so that
 * RoleService never reads operator state (the plane wall — grep-pinned).
 *
 * `password` is the local credential and NEVER federates; cross-mesh recognition
 * is by device-key POSSESSION (operator_devices + mesh_operator_keys), never by
 * replaying this credential. `mesh_operator_id` links a local account to a
 * mesh-wide identity once the operator opts in (null = unlinked).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('server_id');                      // the instance this account is local to
            $table->string('username');                     // local login handle, unique per server
            $table->string('password');                     // hash — $hidden, NEVER federated
            $table->uuid('mesh_operator_id')->nullable();   // linked mesh-wide identity (null = unlinked)
            $table->string('status', 16)->default('active'); // active | suspended | closed
            $table->timestampTz('last_login_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('mesh_operator_id');
        });

        DB::statement('ALTER TABLE operator_accounts ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            'ALTER TABLE operator_accounts ADD CONSTRAINT operator_accounts_status_check '
          ."CHECK (status IN ('active','suspended','closed'))"
        );
        // One active local login per (server, username).
        DB::statement(
            'CREATE UNIQUE INDEX operator_accounts_server_username_unique '
          .'ON operator_accounts (server_id, username) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_accounts');
    }
};
