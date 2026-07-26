<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * K-2: generalise the achievements ledger's identity column.
 *
 * `achievements.journey_id` could only ever hold one of the 13 guided journey
 * arc keys. The K-2 catalogue is 139 achievements (AchievementCatalog), most of
 * which are not journeys at all — a first ballot, a seat taken, a jury served.
 * So the column becomes `award_key`: it holds EITHER a journey arc key (all 13
 * stay valid, unchanged) OR an ACH-* catalogue code.
 *
 * WHAT THIS DOES NOT DO — stated because the phrasing alarmed people upstream:
 * it converts nothing and destroys nothing. A rename preserves every row by
 * definition, and in any case `achievements` holds ZERO rows on both boxes.
 * No data is touched, dropped, cleared or rebuilt.
 *
 * `journey_progress.journey_id` is deliberately NOT renamed. That table really
 * does track journeys, and only journeys. This is a surgical rename of one
 * column on one table, not a global find-and-replace.
 *
 * `title` is deliberately NOT renamed either, though it now holds an i18n key
 * rather than English words. It is a wire field that crosses instances in the
 * FF&C export; renaming it would change the federation wire format for a purely
 * cosmetic gain and drag AchievementFederationTest's assertions along with it.
 * The column's job is unchanged — it names the achievement — only the format of
 * the value changes, which is how most translation layers work anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('achievements', function (Blueprint $table) {
            $table->renameColumn('journey_id', 'award_key');
        });

        // Postgres carries indexes through a column rename but keeps their old
        // NAMES, which would leave `achievements_user_journey_unique` guarding a
        // column called award_key. Rename them so the schema reads honestly.
        DB::statement('ALTER INDEX IF EXISTS achievements_journey_id_index RENAME TO achievements_award_key_index');
        DB::statement('ALTER INDEX IF EXISTS achievements_user_journey_unique RENAME TO achievements_user_award_unique');
    }

    public function down(): void
    {
        DB::statement('ALTER INDEX IF EXISTS achievements_award_key_index RENAME TO achievements_journey_id_index');
        DB::statement('ALTER INDEX IF EXISTS achievements_user_award_unique RENAME TO achievements_user_journey_unique');

        Schema::table('achievements', function (Blueprint $table) {
            $table->renameColumn('award_key', 'journey_id');
        });
    }
};
