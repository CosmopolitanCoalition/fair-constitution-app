<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resolves constitutional constants (legislature floor/ceiling, sizing law, etc.)
 * for a given jurisdiction. Falls back to the planet row's (adm_level = 0, Earth)
 * settings when the target jurisdiction has no own row, and to the constitutional
 * defaults (5/9/cube_root) when no row exists at all.
 *
 * Cached per-jurisdiction for the duration of the request to avoid repeated
 * lookups inside hot paths (the district mapper's auto-composite).
 */
class ConstitutionalDefaults
{
    public const HARD_FLOOR   = 5;
    public const HARD_CEILING = 9;

    private static array $cache = [];

    /**
     * Lowest allowed seats per district. Constitution Art. II §2.
     */
    public static function floor(?string $jurisdictionId = null): int
    {
        return self::resolve($jurisdictionId)['legislature_min_seats'] ?? self::HARD_FLOOR;
    }

    /**
     * Highest allowed seats per district before mandatory subdivision. Constitution Art. II §2.
     */
    public static function ceiling(?string $jurisdictionId = null): int
    {
        return self::resolve($jurisdictionId)['legislature_max_seats'] ?? self::HARD_CEILING;
    }

    /**
     * Law used to compute total legislature size from population.
     * v1 ships with cube_root only.
     */
    public static function sizingLaw(?string $jurisdictionId = null): string
    {
        return self::resolve($jurisdictionId)['legislature_sizing_law'] ?? 'cube_root';
    }

    /**
     * Applies the configured sizing law to a population figure, then clamps
     * to the floor. No ceiling is applied here — a too-large legislature
     * gets subdivided, not truncated.
     */
    public static function sizeFromPopulation(float $population, ?string $jurisdictionId = null): int
    {
        $pop  = max($population, 1.0);
        $law  = self::sizingLaw($jurisdictionId);
        $size = match ($law) {
            'cube_root' => (int) round(pow($pop, 1.0 / 3.0)),
            default     => (int) round(pow($pop, 1.0 / 3.0)),
        };
        return max(self::floor($jurisdictionId), $size);
    }

    /**
     * The DEFAULT line-split template the autoseeder uses when a childless
     * leaf giant must be cut into synthetic district geometries (Setup
     * Option, operator ruling 2026-07-17). One of
     * SubdivisionAutoseedService::TEMPLATES; the mapper's per-run picker can
     * override. Falls back to 'shortest' (the compactness-preserving
     * shortest-splitline) when unset or invalid — never a stringy strip by
     * accident.
     */
    public static function districtingTemplate(?string $jurisdictionId = null): string
    {
        $tpl = self::resolve($jurisdictionId)['districting_autoseed_template'] ?? 'shortest';

        return in_array($tpl, \App\Services\Districting\SubdivisionAutoseedService::TEMPLATES, true)
            ? $tpl
            : 'shortest';
    }

    /**
     * The fractional-seats boundary above which a jurisdiction must be
     * subdivided (cannot fit into a single district because it would round
     * to seats > ceiling). Mathematically: `ceiling + 0.5` — any fractional
     * at or above this rounds to more than the ceiling.
     *
     * With default 5/9 settings this returns 9.5 (legacy hardcoded value).
     * With a custom 3/7 setting this returns 7.5.
     */
    public static function giantThreshold(?string $jurisdictionId = null): float
    {
        return (float) self::ceiling($jurisdictionId) + 0.5;
    }

    /**
     * The fractional-seats floor — the minimum acceptable rounded seat
     * count for a district. A composite sum at or above this rounds to
     * at least the floor. With default 5/9 settings this returns 5.0;
     * with 3/7 it returns 3.0.
     */
    public static function floorBoundary(?string $jurisdictionId = null): float
    {
        return (float) self::floor($jurisdictionId);
    }

    /**
     * The fractional-seats boundary below which a district triggers a
     * floor-override (its rounded seat count would be < floor). With
     * default 5/9 this returns 4.5; with 3/7 it returns 2.5.
     */
    public static function floorOverrideBoundary(?string $jurisdictionId = null): float
    {
        return (float) self::floor($jurisdictionId) - 0.5;
    }

    /**
     * Clear the per-request cache. Useful in tests.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }

    // -------------------------------------------------------------------------

    private static function resolve(?string $jurisdictionId): array
    {
        $key = $jurisdictionId ?? '__fallback__';
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $row = null;
        if ($jurisdictionId !== null) {
            $row = DB::table('constitutional_settings')
                ->where('jurisdiction_id', $jurisdictionId)
                ->first();
        }

        // Fallback: the planet row (adm_level = 0, "Earth").
        if ($row === null) {
            $row = DB::table('constitutional_settings as cs')
                ->join('jurisdictions as j', 'j.id', '=', 'cs.jurisdiction_id')
                ->where('j.adm_level', 0)
                ->orderBy('j.created_at')
                ->select('cs.*')
                ->first();
        }

        $resolved = $row
            ? (array) $row
            : [
                'legislature_min_seats'  => self::HARD_FLOOR,
                'legislature_max_seats'  => self::HARD_CEILING,
                'legislature_sizing_law' => 'cube_root',
            ];

        return self::$cache[$key] = $resolved;
    }
}
