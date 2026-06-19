<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase K-1 (The Civic Record plane) — profiles + the space/subforum topology.
 *
 *  - social_profiles : one pseudonymous profile per user (display_name, NEVER name/email).
 *                      A profile choice never gates a right (Art. I).
 *  - social_spaces   : a public_square OR halls space per jurisdiction (one each, public);
 *                      private org/user spaces are unconstrained (Art. I private half).
 *  - social_subforums: auto-bound (one per live governance object) — the partial-unique on
 *                      (governing_object_type, governing_object_id) is the reconciler's
 *                      idempotency key.
 *
 * Additive only — no protected migration (jurisdictions/ballots/audit_log) is touched.
 * Enums are app-layer strings + raw CHECK (not PG ENUM), matching the house convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');                 // soft ref to users.id (federation-safe)
            $table->string('handle', 64)->nullable();
            $table->string('display_name', 120)->nullable();   // pseudonym — never name/email
            $table->text('bio')->nullable();
            $table->string('visibility', 12)->default('public');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('user_id');
        });

        DB::statement('ALTER TABLE social_profiles ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE social_profiles ADD CONSTRAINT social_profiles_visibility_check "
          ."CHECK (visibility IN ('public','jurisdiction','private'))"
        );
        // One live profile per user; one live handle (case-insensitive) when set.
        DB::statement(
            'CREATE UNIQUE INDEX social_profiles_user_unique ON social_profiles '
          .'(user_id) WHERE deleted_at IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX social_profiles_handle_unique ON social_profiles '
          .'(lower(handle)) WHERE handle IS NOT NULL AND deleted_at IS NULL'
        );

        Schema::create('social_spaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('jurisdiction_id');         // soft ref to jurisdictions.id
            $table->string('space_type', 16);        // public_square | halls
            $table->string('title', 200);
            $table->string('slug', 120)->nullable();
            $table->string('status', 12)->default('open');   // open | archived (never 'locked')
            $table->boolean('is_private')->default(false);   // org/user self-moderated space
            $table->uuid('owner_org_id')->nullable();        // set for private org spaces (R-23)
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('jurisdiction_id');
            $table->index('owner_org_id');
        });

        DB::statement('ALTER TABLE social_spaces ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE social_spaces ADD CONSTRAINT social_spaces_type_check "
          ."CHECK (space_type IN ('public_square','halls'))"
        );
        DB::statement(
            "ALTER TABLE social_spaces ADD CONSTRAINT social_spaces_status_check "
          ."CHECK (status IN ('open','archived'))"
        );
        // Exactly one PUBLIC square + one PUBLIC halls per jurisdiction; private spaces unconstrained.
        DB::statement(
            'CREATE UNIQUE INDEX social_spaces_jur_type_unique ON social_spaces '
          .'(jurisdiction_id, space_type) WHERE is_private = false AND deleted_at IS NULL'
        );

        Schema::create('social_subforums', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('space_id');
            $table->string('governing_object_type', 40)->nullable();  // null for the bare square subforum
            $table->uuid('governing_object_id')->nullable();          // soft ref to the bound object
            $table->string('title', 200);
            $table->string('status', 12)->default('open');            // open | archived
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('governing_object_id');
            $table->foreign('space_id')->references('id')->on('social_spaces')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE social_subforums ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE social_subforums ADD CONSTRAINT social_subforums_status_check "
          ."CHECK (status IN ('open','archived'))"
        );
        // THE auto-bind reconciler invariant: one live subforum per (object_type, object_id).
        DB::statement(
            'CREATE UNIQUE INDEX social_subforums_object_unique ON social_subforums '
          .'(governing_object_type, governing_object_id) '
          .'WHERE governing_object_type IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('social_subforums');
        Schema::dropIfExists('social_spaces');
        Schema::dropIfExists('social_profiles');
    }
};
