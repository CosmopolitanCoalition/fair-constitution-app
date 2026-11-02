<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Short invite links. The `handle` column becomes THE code a link carries (8 URL-safe characters, or a
 * custom name), so the long `handle.secret` token and its Argon2id hash are gone.
 *
 * Every invite minted before this had a handle that was not a credential, only half of one. Left live,
 * a bare old handle would now open the link, so all of them are revoked here. Old links stop working
 * by operator order (2026-11-02); nobody needs them.
 *
 * Replaces: `token_hash` (the secret half) is dropped because the code is the whole credential now.
 * Defence moves to the landing route: a throttle, a miss lockout per address, expiry, use limits and
 * revocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invites')->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);

        Schema::table('invites', function (Blueprint $t) {
            $t->dropColumn('token_hash');
            $t->boolean('is_custom')->default(false);
        });
        DB::statement('ALTER TABLE invites ALTER COLUMN handle TYPE varchar(40)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE invites ALTER COLUMN handle TYPE varchar(16) USING left(handle, 16)');
        Schema::table('invites', function (Blueprint $t) {
            $t->dropColumn('is_custom');
            $t->text('token_hash')->default('');
        });
    }
};
