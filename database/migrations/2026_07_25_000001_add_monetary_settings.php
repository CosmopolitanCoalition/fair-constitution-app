<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase L slice L-1 — the monetary levers (docs/plans/economy/ECONOMY_ENGINE_PLAN.md §4.1).
 *
 * Five new amendable keys joining the nine economy columns setup-wizard-v2
 * already ships. Together they become the first monetary levers on the
 * dual-door rail: from here, a stipend rate or a funding source moves ONLY
 * through F-LEG-031 → EnactmentService, never an admin knob (Art. V §5).
 *
 * NULLABLE — deliberately, and unlike the nine that came before.
 * SettingsResolver::resolve() takes the first NON-NULL value walking up the
 * jurisdiction chain, so a NOT NULL DEFAULT column can never inherit: every
 * child row answers with its own default and the root's value is unreachable.
 * The nine setup-wizard-v2 columns have exactly that defect today (951,622
 * rows on the game box all carrying the same defaults — audit §E5). The house
 * pattern is nullable column + code default at the call site
 * (SettingsResolver::resolveInt($jur, $col, $default)), which is what these use.
 *
 * Defaults live in ConstitutionalDefaults, not in the DDL, for the same reason.
 * Operator rulings 2026-07-25: the stipend is ALWAYS ON for all players, and
 * funding source is a founder choice (mint or draw from treasury).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('constitutional_settings', function (Blueprint $table) {
            // Master switch. Operator ruling: on for all players; a legislature
            // may switch it off by act, but a fresh world pays from day one.
            $table->boolean('stipend_enabled')->nullable();

            // minted        — the root mints each run (issuance_events + ledger)
            // treasury_draw — the run debits a designated treasury account
            $table->string('stipend_funding_source', 16)->nullable();

            // Cadence of the stipend sweep. No new CLK code (Phase-D precedent:
            // data + a settings-driven sweep, not a new clock).
            $table->integer('stipend_period_days')->nullable();

            // Monetary levers, basis points. [POLICY] — the Template sets no
            // rate; these exist so a legislature can, on the public record.
            $table->integer('issuance_rate_bps')->nullable();
            $table->integer('inflation_target_bps')->nullable();
        });

        DB::statement(
            "ALTER TABLE constitutional_settings
             ADD CONSTRAINT constitutional_settings_stipend_funding_source_check
             CHECK (stipend_funding_source IS NULL
                    OR stipend_funding_source IN ('minted', 'treasury_draw'))"
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE constitutional_settings
             DROP CONSTRAINT IF EXISTS constitutional_settings_stipend_funding_source_check'
        );

        Schema::table('constitutional_settings', function (Blueprint $table) {
            $table->dropColumn([
                'stipend_enabled',
                'stipend_funding_source',
                'stipend_period_days',
                'issuance_rate_bps',
                'inflation_target_bps',
            ]);
        });
    }
};
