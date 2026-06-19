<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase K-1 (review fix) — back the per-space GENERAL subforum (governing_object_type IS NULL)
 * with a partial-unique index. The object-bound subforums are already covered by
 * social_subforums_object_unique (WHERE governing_object_type IS NOT NULL); the general
 * subforum was not, so SocialSpaceService::resolveSubforum's firstOrCreate had no backing
 * constraint — two concurrent first-posts into a brand-new space could both insert. Cosmetic
 * (no invariant breached) but closed here: one live general subforum per space. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX social_subforums_general_unique ON social_subforums '
          .'(space_id) WHERE governing_object_type IS NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS social_subforums_general_unique');
    }
};
