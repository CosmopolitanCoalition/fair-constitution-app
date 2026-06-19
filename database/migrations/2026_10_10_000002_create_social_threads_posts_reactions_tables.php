<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase K-1 — threads, posts, and the LOCAL-ONLY reaction graph.
 *
 *  - social_threads : a discussion in a subforum; published_record_id is THE back-pointer to
 *                     the public_records row a hall testimony seals into (set by F-SOC-002).
 *                     Status has NO 'removed' value — the public square is uncensorable (Art. I).
 *  - social_posts   : the post body; is_official + acting_seat tag a seat-holder speaking in
 *                     office (validated against live derived roles, authority never stored).
 *  - social_reactions: LOCAL-ONLY — never federates, never reaches the public register/chain
 *                     (FORBIDDEN_SUBJECT_TYPES is the tripwire; the real boundary is that these
 *                     are plain inserts that never call PublicRecordService::publish()).
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_threads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('subforum_id');
            $table->uuid('author_user_id');
            $table->string('author_display', 120);            // pseudonym snapshot at creation
            $table->string('title', 300);
            $table->string('status', 12)->default('open');     // open | archived (NO 'removed')
            $table->uuid('published_record_id')->nullable();   // -> public_records.id (uuid, NOT seq)
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('author_user_id');
            $table->index('published_record_id');
            $table->foreign('subforum_id')->references('id')->on('social_subforums')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE social_threads ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE social_threads ADD CONSTRAINT social_threads_status_check "
          ."CHECK (status IN ('open','archived'))"
        );

        Schema::create('social_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('author_user_id');
            $table->string('author_display', 120);
            $table->text('body');
            $table->boolean('is_official')->default(false);
            $table->string('acting_seat', 40)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('author_user_id');
            $table->foreign('thread_id')->references('id')->on('social_threads')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE social_posts ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE social_posts ADD CONSTRAINT social_posts_acting_seat_check "
          ."CHECK (acting_seat IS NULL OR acting_seat IN "
          ."('legislature_member','committee_seat','exec_seat','judicial_seat'))"
        );

        Schema::create('social_reactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('post_id');
            $table->uuid('user_id');
            $table->string('kind', 16);     // up | heart | insightful | flag (behavioral, never a viewpoint takedown)
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('user_id');
            $table->foreign('post_id')->references('id')->on('social_posts')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE social_reactions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE social_reactions ADD CONSTRAINT social_reactions_kind_check "
          ."CHECK (kind IN ('up','heart','insightful','flag'))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX social_reactions_unique ON social_reactions '
          .'(post_id, user_id, kind) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('social_reactions');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_threads');
    }
};
