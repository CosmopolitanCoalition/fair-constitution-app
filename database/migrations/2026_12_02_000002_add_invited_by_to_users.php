<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Growth attribution: who brought a user in. Set ONCE, at signup, when the new account
 * redeems an invite (InviteService::consume). Nullable — organic signups have no inviter.
 * A pure record column: it never confers any right or power (Art. I — rights derive from
 * residency + the audit chain, never from who invited you).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('invited_by_user_id')->nullable()->after('home_server_id');
            $table->index('invited_by_user_id');
        });

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_invited_by_user_id_foreign '
          .'FOREIGN KEY (invited_by_user_id) REFERENCES users (id) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_invited_by_user_id_foreign');
            $table->dropIndex(['invited_by_user_id']);
            $table->dropColumn('invited_by_user_id');
        });
    }
};
