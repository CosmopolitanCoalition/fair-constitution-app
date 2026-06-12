<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WI-6 — Clock registry + timers (the constitutional scheduler).
 *
 * `clocks` is the REGISTRY: one row per constitutional clock (CLK-01…CLK-21,
 * seeded by ClockRegistrySeeder from the canonical scheduler spec). The
 * registry row records the clock's TYPE semantics, its constitutional
 * default value, whether the value is amendable (resolved from
 * `constitutional_settings` per jurisdiction at EVALUATION time — never
 * frozen at arm time), the workflow each fire triggers, and the
 * constitutional basis.
 *
 * `clock_timers` is the RUNTIME: one row per armed instance of a clock
 * (subject-scoped — a residency claim, a term, an emergency power…).
 * `fires_at` NULL = threshold-watch (the sweep evaluates the watched
 * quantity instead of a deadline). `override_value` is the per-case slot
 * needed by CLK-11/CLK-12 in Phase E (judiciary sets the window per
 * finding) — schema lands now so Phase E does not migrate this table.
 *
 * Type semantics (clocks.html): recurring intervals re-arm on fire;
 * countdowns expire once; windows open and close; thresholds watch a
 * quantity and fire on crossing; derived values are computed, never armed;
 * flags are term-scoped markers.
 *
 * Also adds `constitutional_settings.critical_population_threshold`
 * (nullable int) — the per-jurisdiction CLK-06 activation threshold.
 * NULL = inherit (ancestor walk, then config('cga.critical_population_default')).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── clocks (registry) ───────────────────────────────────────────────
        Schema::create('clocks', function (Blueprint $table) {
            $table->string('id', 8)->primary();          // 'CLK-01' … 'CLK-21'
            $table->string('name', 64);
            $table->string('type', 12);
            $table->jsonb('default_value')->default('{}');
            $table->boolean('amendable')->default(false);
            $table->string('fires_workflow', 64)->nullable();
            $table->text('basis')->nullable();
            $table->timestampsTz();
        });

        DB::statement(
            "ALTER TABLE clocks ADD CONSTRAINT clocks_type_check " .
            "CHECK (type IN ('recurring', 'countdown', 'window', 'threshold', 'derived', 'flag'))"
        );

        // ── clock_timers (runtime) ──────────────────────────────────────────
        Schema::create('clock_timers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('clock_id', 8);
            $table->foreign('clock_id')->references('id')->on('clocks')->restrictOnDelete();

            // No FK: dev mass-reseeds hard-delete jurisdictions; a timer row
            // must never block that, and stale timers are cancelled by sweeps.
            $table->uuid('jurisdiction_id')->nullable();

            // Polymorphic subject: residency_claim, term, emergency_power,
            // vacancy, election… (string enum, app-layer validated).
            $table->string('subject_type', 64)->nullable();
            $table->uuid('subject_id')->nullable();

            $table->timestampTz('armed_at');
            // NULL = threshold-watch (no deadline; the sweep evaluates the
            // watched quantity directly).
            $table->timestampTz('fires_at')->nullable();

            $table->string('state', 12)->default('armed');

            $table->jsonb('payload')->default('{}');

            // Phase E per-case override slot (CLK-11/CLK-12: window set by
            // the judiciary per finding). Unused in Phase A by design.
            $table->jsonb('override_value')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['state', 'fires_at']);
            $table->index(['clock_id', 'jurisdiction_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement('ALTER TABLE clock_timers ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            "ALTER TABLE clock_timers ADD CONSTRAINT clock_timers_state_check " .
            "CHECK (state IN ('armed', 'fired', 'cancelled', 'expired'))"
        );

        // ── constitutional_settings.critical_population_threshold ───────────
        // CLK-06: verified residents required before a dormant jurisdiction
        // activates (WF-JUR-01). Nullable = inherit from the nearest ancestor
        // with a value, falling back to config('cga.critical_population_default')
        // (dev default 1 — a single verified resident activates; production
        // tiers land later per owner ruling #15).
        Schema::table('constitutional_settings', function (Blueprint $table) {
            $table->integer('critical_population_threshold')->nullable()
                ->comment('CLK-06 activation threshold (verified residents). NULL = inherit ancestor, then code default.');
        });
    }

    public function down(): void
    {
        Schema::table('constitutional_settings', function (Blueprint $table) {
            $table->dropColumn('critical_population_threshold');
        });

        Schema::dropIfExists('clock_timers');
        Schema::dropIfExists('clocks');
    }
};
