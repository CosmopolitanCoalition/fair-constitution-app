<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase I — the activation tier CURVE parameters.
 *
 *     threshold(P) = clamp(ceil(k · P^(1/exponent)), floor, cap)
 *
 * Five columns, set ONCE at the planet root in practice — "one amendable row,
 * not 951k thresholds" (roadmap §Phase I). Per-jurisdiction overrides remain
 * possible through the existing cascade for any subtree that needs one.
 *
 * ── Why every column is NULLABLE, and why that is not a style choice ──────
 * SettingsResolver picks the first ancestor row whose column IS NOT NULL. A
 * `NOT NULL DEFAULT` would give all ~951k jurisdiction-scoped rows a non-null
 * value, so every lookup would stop at the jurisdiction's own row and the
 * ancestor cascade would never run — the planet-root setting could never
 * reach anybody. NULL means "inherit"; that is the whole mechanism.
 *
 * ── Why enabled/k are integers rather than boolean/numeric ────────────────
 * They resolve through SettingsResolver::resolveInt, which casts to int. A
 * PostgreSQL boolean arrives from PDO as 't'/'f', and (int) 't' is 0 — a
 * silent always-off. Storing 0/1 keeps the whole curve on one resolver path.
 * Fractional k is deliberately not expressible here; it belongs in config if
 * it is ever wanted.
 *
 * ⚑ OPEN, RESERVED TO THE OPERATOR: these are NOT yet registered in
 * SettingsController::REGISTER_KEYS or ConstitutionalValidator::SETTING_BOUNDS,
 * so no legislature can amend them and the settings register will not display
 * them. Registering them means editing two PROTECTED files under
 * constitutional review; declaring the curve founder-only configuration is an
 * equally legitimate answer. This migration deliberately does not pre-empt
 * that ruling — it ships the mechanism, not the amendability decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('constitutional_settings', function (Blueprint $table): void {
            $table->smallInteger('activation_tier_enabled')->nullable();
            $table->integer('activation_tier_k')->nullable();
            $table->smallInteger('activation_tier_exponent')->nullable();
            $table->integer('activation_tier_floor')->nullable();
            $table->integer('activation_tier_cap')->nullable();
        });

        DB::statement("COMMENT ON COLUMN constitutional_settings.activation_tier_enabled IS "
            ."'Phase I tier curve on/off (0/1). NULL = inherit ancestor, then config. "
            ."Off = the dev posture: one verified resident activates.'");
        DB::statement("COMMENT ON COLUMN constitutional_settings.activation_tier_k IS "
            ."'Phase I tier curve multiplier k in clamp(ceil(k*P^(1/exponent)), floor, cap). NULL = inherit.'");
        DB::statement("COMMENT ON COLUMN constitutional_settings.activation_tier_exponent IS "
            ."'Phase I tier curve root (3 = cube root, mirroring Taagepera). NULL = inherit.'");
        DB::statement("COMMENT ON COLUMN constitutional_settings.activation_tier_floor IS "
            ."'Phase I minimum verified residents to boot a government. NOT the legislature seat "
            ."floor - different law, coincident default. NULL = inherit.'");
        DB::statement("COMMENT ON COLUMN constitutional_settings.activation_tier_cap IS "
            ."'Phase I maximum verified residents any place can be asked for. Bounded by "
            ."ActivationTierService::HARD_CAP so no ancestor can render a subtree permanently "
            ."unbootable (Art. I). NULL = inherit.'");
    }

    public function down(): void
    {
        Schema::table('constitutional_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'activation_tier_enabled',
                'activation_tier_k',
                'activation_tier_exponent',
                'activation_tier_floor',
                'activation_tier_cap',
            ]);
        });
    }
};
