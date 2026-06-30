<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User-owned PRIVATE ROOMS — the "Art. I private half". A player owns a private SocialSpace
 * (a group / DM room) and invites friends; only members enter. This is OFF the public-commons
 * plane: no testimony, no public record, no governance — gated by MEMBERSHIP, owner-moderated.
 *
 *  - `social_spaces` gains `owner_user_id` (org-ownership already existed; user-ownership did not)
 *    and a 'group' space_type. Private spaces are already exempt from the public-uniqueness index.
 *  - `matrix_rooms` gains a 'user_private' room_type + a 'social_space' entity_type so a private
 *    room binds to its OWN SocialSpace id (per-room unique), is_public=false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_spaces', function (Blueprint $table) {
            $table->uuid('owner_user_id')->nullable()->after('owner_org_id');
            $table->index('owner_user_id');
        });
        DB::statement(
            'ALTER TABLE social_spaces ADD CONSTRAINT social_spaces_owner_user_id_foreign '
          .'FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE'
        );
        DB::statement('ALTER TABLE social_spaces DROP CONSTRAINT social_spaces_type_check');
        DB::statement(
            "ALTER TABLE social_spaces ADD CONSTRAINT social_spaces_type_check "
          ."CHECK (space_type IN ('public_square','halls','group'))"
        );

        DB::statement('ALTER TABLE matrix_rooms DROP CONSTRAINT matrix_rooms_room_type_check');
        DB::statement(
            "ALTER TABLE matrix_rooms ADD CONSTRAINT matrix_rooms_room_type_check "
          ."CHECK (room_type IN ('m.space','commons','org_public','org_private','institution','user_private'))"
        );
        DB::statement('ALTER TABLE matrix_rooms DROP CONSTRAINT matrix_rooms_entity_type_check');
        DB::statement(
            "ALTER TABLE matrix_rooms ADD CONSTRAINT matrix_rooms_entity_type_check "
          ."CHECK (entity_type IN ('jurisdiction','organization','legislature','executive','judiciary',"
          ."'board','bill','referendum_question','petition','committee_meeting','candidacy','social_space'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE matrix_rooms DROP CONSTRAINT matrix_rooms_entity_type_check');
        DB::statement(
            "ALTER TABLE matrix_rooms ADD CONSTRAINT matrix_rooms_entity_type_check "
          ."CHECK (entity_type IN ('jurisdiction','organization','legislature','executive','judiciary',"
          ."'board','bill','referendum_question','petition','committee_meeting','candidacy'))"
        );
        DB::statement('ALTER TABLE matrix_rooms DROP CONSTRAINT matrix_rooms_room_type_check');
        DB::statement(
            "ALTER TABLE matrix_rooms ADD CONSTRAINT matrix_rooms_room_type_check "
          ."CHECK (room_type IN ('m.space','commons','org_public','org_private','institution'))"
        );

        DB::statement('ALTER TABLE social_spaces DROP CONSTRAINT social_spaces_type_check');
        DB::statement(
            "ALTER TABLE social_spaces ADD CONSTRAINT social_spaces_type_check "
          ."CHECK (space_type IN ('public_square','halls'))"
        );
        Schema::table('social_spaces', function (Blueprint $table) {
            $table->dropForeign('social_spaces_owner_user_id_foreign');
            $table->dropIndex(['owner_user_id']);
            $table->dropColumn('owner_user_id');
        });
    }
};
