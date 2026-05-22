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
