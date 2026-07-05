<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Setup-wizard v2 — additive on top of the flattened baseline (first REAL-dated
 * migration, 2026-07-05).
 *
 *  1. instance_settings.game_mode — the WORLD-property mode chosen at founding
 *     (production | sandbox). NULL until the operator picks it at the
 *     constitutional-defaults step. Sandbox is where the dev toolbox is legit
 *     (assume-any-role / manufacture qualifications) — a world property, never
 *     a code flag ([[feedback_no_dev_exceptions_and_test_discipline]]).
 *
 *  2. constitutional_settings — Economy defaults set at founding (v3 mockup
 *     `Constitution & Economy defaults` screen). AMENDABLE settings (the
 *     currency's EXISTENCE is Art. V §5 root-reserved; these founding DEFAULTS
 *     for its name and the stipend/pay schedule are ordinary legislative
 *     settings, F-LEG-031). Additive columns with Template defaults so existing
 *     rows and the baseline dump keep working untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            // production | sandbox. NULL = not yet chosen (setup in progress).
            $table->string('game_mode', 16)->nullable()->after('setup_mode');
        });

        DB::statement(
            "ALTER TABLE instance_settings ADD CONSTRAINT instance_settings_game_mode_check "
          ."CHECK (game_mode IS NULL OR game_mode IN ('production', 'sandbox'))"
        );

        Schema::table('constitutional_settings', function (Blueprint $table) {
            // Currency identity (founding defaults; existence is root-reserved).
            $table->string('currency_name', 64)->default('Civic Value Unit')->after('initiative_petition_threshold_pct');
            $table->string('currency_code', 8)->default('CVU')->after('currency_name');
            $table->string('currency_symbol', 8)->default('ç')->after('currency_code');
            // Civic stipend + per-role pay schedule (whole CVU units).
            $table->integer('civic_stipend_floor')->default(50)->after('currency_symbol');
            $table->integer('stipend_bump_cap')->default(20)->after('civic_stipend_floor');
            $table->integer('pay_node_operator')->default(8)->after('stipend_bump_cap');
            $table->integer('pay_social_moderator')->default(5)->after('pay_node_operator');
            $table->integer('pay_office_holder')->default(12)->after('pay_social_moderator');
            // monthly | quarterly | per_cycle
            $table->string('stipend_interval', 16)->default('monthly')->after('pay_office_holder');
        });

        DB::statement(
            "ALTER TABLE constitutional_settings ADD CONSTRAINT constitutional_settings_stipend_interval_check "
          ."CHECK (stipend_interval IN ('monthly', 'quarterly', 'per_cycle'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE instance_settings DROP CONSTRAINT IF EXISTS instance_settings_game_mode_check');
        DB::statement('ALTER TABLE constitutional_settings DROP CONSTRAINT IF EXISTS constitutional_settings_stipend_interval_check');

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('game_mode');
        });
        Schema::table('constitutional_settings', function (Blueprint $table) {
            $table->dropColumn([
                'currency_name', 'currency_code', 'currency_symbol',
                'civic_stipend_floor', 'stipend_bump_cap',
                'pay_node_operator', 'pay_social_moderator', 'pay_office_holder',
                'stipend_interval',
            ]);
        });
    }
};
