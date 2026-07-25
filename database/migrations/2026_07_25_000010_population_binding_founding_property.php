<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POPULATION BINDING — a founding world property (operator ruling 2026-07-25).
 *
 * Whether this world's institutions are bound to REAL-WORLD population.
 *
 *   'real' — institutions scale to the actual population of each jurisdiction.
 *            A place with no people gets no institutions; a place with
 *            billions gets a large, multi-tiered one. This is the Earth /
 *            demo posture: "pretend we could rebuild all of civilisation on
 *            earth from scratch."
 *
 *   'free' — population imposes nothing. Every jurisdiction that exists is
 *            entitled to its full institution set regardless of headcount, so
 *            a handful of people can run a whole world, a tabletop group can
 *            play, or one organisation can adopt a single component.
 *
 * ── Why this is a FOUNDING property and not an amendable setting ──────────
 * It belongs to the same family as `map_mode`, `time_mode` and
 * `time_scale_seconds_per_year` on this table: chosen when the world is
 * founded, describing what KIND of world this is. It is deliberately NOT a
 * `constitutional_settings` row, because it is not a policy a legislature
 * adopts — a legislature inside a world cannot vote on whether that world is
 * bound to real demography, any more than it can vote on whether time is
 * compressed.
 *
 * It is also not a permanent lock: it is the founder's choice at setup, and
 * the operator may re-found. What it must never become is a per-jurisdiction
 * dial, because then one place could exempt itself from the world's physics.
 *
 * Default 'real' preserves current behaviour for every existing instance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->string('population_binding', 16)->default('real');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE instance_settings
              ADD CONSTRAINT instance_settings_population_binding_check
              CHECK (population_binding IN ('real', 'free'))
        SQL);

        DB::statement("COMMENT ON COLUMN instance_settings.population_binding IS "
            ."'Founding world property (operator ruling 2026-07-25). real = institutions scale to "
            ."actual population; a jurisdiction with no people gets none. free = population imposes "
            ."nothing, every jurisdiction gets its full set. Same family as time_mode - chosen at "
            ."founding, never a per-jurisdiction dial and never a legislative setting.'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE instance_settings DROP CONSTRAINT IF EXISTS instance_settings_population_binding_check');

        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropColumn('population_binding');
        });
    }
};
