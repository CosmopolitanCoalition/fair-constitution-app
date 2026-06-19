<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase K-1 — the LOCAL-ONLY follow + membership graph.
 *
 *  - social_follows     : who follows a user/space/subforum — LOCAL-ONLY, never federates.
 *  - social_memberships : space membership; role='owner' is the in-handler self-moderation
 *                         check for PRIVATE spaces ONLY (NOT a public-square "moderator" bit,
 *                         NOT a derived office role). block_user_id is the M-3 per-user block —
 *                         client-side curation, never federates, never audited.
 *
 * Both tables are LOCAL-ONLY: they never call PublicRecordService::publish() and their
 * subject types are in FORBIDDEN_SUBJECT_TYPES as a belt-and-suspenders tripwire. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_follows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('follower_user_id');
            $table->string('target_type', 20);   // user | space | subforum
            $table->uuid('target_id');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('follower_user_id');
            $table->index('target_id');
        });

        DB::statement('ALTER TABLE social_follows ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE social_follows ADD CONSTRAINT social_follows_target_type_check "
          ."CHECK (target_type IN ('user','space','subforum'))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX social_follows_unique ON social_follows '
          .'(follower_user_id, target_type, target_id) WHERE deleted_at IS NULL'
        );

        Schema::create('social_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('space_id');
            $table->uuid('user_id');
            $table->string('role', 16)->default('member');   // member | owner (owner only on PRIVATE spaces)
            $table->uuid('block_user_id')->nullable();        // M-3 per-user block — client-side, never federates/audits
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('user_id');
            $table->index('block_user_id');
            $table->foreign('space_id')->references('id')->on('social_spaces')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE social_memberships ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE social_memberships ADD CONSTRAINT social_memberships_role_check "
          ."CHECK (role IN ('member','owner'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('social_memberships');
        Schema::dropIfExists('social_follows');
    }
};
