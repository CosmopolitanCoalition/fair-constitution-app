<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ITEM ⑤ — THE AutoscaleResizeRepair SQL-CLAMP, MADE HONEST (Wave 4, lane 1;
 * the deferred latent item from the Wave 3 adversarial pass).
 *
 * AutoscaleResizeRepairCommand's Type B ladder (the set-based SQL mirror of
 * TypeBSeatLadder) opens with
 *
 *     f0 = LEAST(GREATEST(COALESCE(cs.type_b_seats_per_child, 5), 2), 5)
 *
 * i.e. it SILENTLY CLAMPS the starting rep-per-constituent to [2, 5]. The PHP
 * service does not clamp — it honours arbitrary starts. So a setting outside
 * [2, 5] would make the SQL and PHP paths DISAGREE (the SQL would quietly seat
 * a different chamber than the write path). Today no surface writes a value
 * outside [2, 5], so the divergence is latent — but "latent" is not "safe",
 * and a mass pass over ~9,708 chambers is exactly where a silent clamp hides.
 *
 * This makes the clamp's assumption a GUARANTEED invariant instead of a silent
 * correction: type_b_seats_per_child must be NULL or within the ladder's own
 * [2, 5] band (top rung 5, floor rung 2 — CLAUDE.md "Bicameral Support", the
 * 5 → 4 → 3 → 2 ladder). With the CHECK in place the SQL LEAST/GREATEST can
 * never actually fire, so the mirror and the service can never diverge.
 *
 * Additive, REAL-dated 2026-07-29. NOT NULL-safe (NULL means "use the default
 * 5"); the dev box carries only the default 5, so the constraint validates
 * against existing data without a rewrite.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'constitutional_settings_type_b_seats_per_child_range';

    public function up(): void
    {
        DB::statement('ALTER TABLE constitutional_settings DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement(
            'ALTER TABLE constitutional_settings ADD CONSTRAINT '.self::CONSTRAINT.' CHECK ('
            .'type_b_seats_per_child IS NULL '
            .'OR (type_b_seats_per_child >= 2 AND type_b_seats_per_child <= 5))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE constitutional_settings DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
    }
};
