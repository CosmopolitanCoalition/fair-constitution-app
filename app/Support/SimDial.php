<?php

namespace App\Support;

use App\Models\InstanceSettings;

/**
 * THE SIMULATION DIAL (operator order 2026-09-19): the single owner of the
 * population-sample default and bounds, and of the read that turns the stored
 * Step 4 choices into the `sim:start` options. Pure functions, so the rule is
 * pinnable without a database.
 *
 *   sample_pct    percent of each LEAF population minted as people
 *                 (IdentityStage caps one leaf at MAX_PER_JURISDICTION).
 *   roster_floor  where the dial leaves a place short of the candidates its
 *                 election needs, mint the shortfall for that place
 *                 (ElectionStage). Off: that place files to review.
 */
final class SimDial
{
    /** One resident per thousand: the operator's standing dial. */
    public const DEFAULT_PCT = 0.1;

    public const MIN_PCT = 0.0;

    public const MAX_PCT = 100.0;

    /** Stored precision (instance_settings.sim_sample_pct is decimal(7,4)). */
    public const DECIMALS = 4;

    private function __construct() {}

    /** A dial value from any input: non-numeric gives the default, the rest clamps to the bounds. */
    public static function clamp(mixed $value): float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return self::DEFAULT_PCT;
        }

        return round(max(self::MIN_PCT, min(self::MAX_PCT, (float) $value)), self::DECIMALS);
    }

    /**
     * The stored choices. A settings row that predates the columns reads as
     * the defaults (dial 0.1, floor on).
     *
     * @return array{sample_pct: float, roster_floor: bool}
     */
    public static function fromSettings(InstanceSettings $settings): array
    {
        $floor = $settings->sim_roster_floor;

        return [
            'sample_pct'   => self::clamp($settings->sim_sample_pct),
            'roster_floor' => $floor === null ? true : (bool) $floor,
        ];
    }

    /**
     * The instance_settings writes for a Step 4 lock. Only the keys the
     * request carries are written, so an older page that sends nothing leaves
     * the stored choices alone. A world that is not a sandbox never simulates.
     *
     * @param  array<string,mixed>  $input  simulate_at_scale, sim_sample_pct, sim_roster_floor (each optional)
     * @return array<string,bool|float>
     */
    public static function lockWrites(array $input, ?string $gameMode): array
    {
        $out = [];

        if (array_key_exists('simulate_at_scale', $input)) {
            $out['simulate_at_scale'] = $gameMode === 'sandbox'
                && filter_var($input['simulate_at_scale'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('sim_sample_pct', $input)) {
            $out['sim_sample_pct'] = self::clamp($input['sim_sample_pct']);
        }
        if (array_key_exists('sim_roster_floor', $input)) {
            $out['sim_roster_floor'] = filter_var($input['sim_roster_floor'], FILTER_VALIDATE_BOOLEAN);
        }

        return $out;
    }

    /**
     * The `sim:start` option pair for the stored choices, in the shape
     * SimRunControl::start consumes.
     *
     * @return array{sample-pct: float, no-floor: bool}
     */
    public static function startOptions(InstanceSettings $settings): array
    {
        $dial = self::fromSettings($settings);

        return [
            'sample-pct' => $dial['sample_pct'],
            'no-floor'   => ! $dial['roster_floor'],
        ];
    }
}
