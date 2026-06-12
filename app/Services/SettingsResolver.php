<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * WI-6 — per-jurisdiction resolution of amendable constitutional values
 * at EVALUATION time (never frozen at arm time).
 *
 * Walk: the jurisdiction's own constitutional_settings row → nearest
 * ancestor with a non-null value (recursive CTE up parent_id) → null
 * (caller applies the code/registry default).
 *
 * This generalizes the two existing walks in the codebase:
 *  - ResidencyService::thresholdDays (own row → planet row → code default)
 *  - ConstitutionalDefaults::resolve (own row → planet row, whole-row)
 * by checking EVERY intermediate ancestor, which matters once sub-planet
 * jurisdictions start carrying their own amended settings. Existing
 * callers keep their shortcut (planet fallback ≡ ancestor walk while only
 * the planet row exists); new clock/activation code resolves through here.
 */
class SettingsResolver
{
    /** Cycle guard for the ancestor walk (matches ResidencyService). */
    private const MAX_CHAIN_DEPTH = 32;

    /** Per-request memo: "<jurisdiction>:<column>" => resolved value|null. */
    private array $memo = [];

    /**
     * First non-null value of $column on the jurisdiction's settings row or
     * the nearest ancestor's, or null when no row in the chain carries one.
     */
    public function resolve(string $jurisdictionId, string $column): mixed
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $column)) {
            throw new InvalidArgumentException("Illegal settings column name [{$column}].");
        }

        $key = $jurisdictionId . ':' . $column;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $row = DB::selectOne(
            "WITH RECURSIVE chain AS (
                SELECT j.id, j.parent_id, 0 AS depth
                FROM jurisdictions j
                WHERE j.id = ? AND j.deleted_at IS NULL

                UNION ALL

                SELECT p.id, p.parent_id, c.depth + 1
                FROM chain c
                JOIN jurisdictions p ON p.id = c.parent_id AND p.deleted_at IS NULL
                WHERE c.depth < " . self::MAX_CHAIN_DEPTH . "
            )
            SELECT cs.{$column} AS value
            FROM chain c
            JOIN constitutional_settings cs ON cs.jurisdiction_id = c.id
            WHERE cs.{$column} IS NOT NULL
            ORDER BY c.depth
            LIMIT 1",
            [$jurisdictionId]
        );

        return $this->memo[$key] = $row?->value;
    }

    public function resolveInt(string $jurisdictionId, string $column, int $default): int
    {
        $value = $this->resolve($jurisdictionId, $column);

        return $value === null ? $default : (int) $value;
    }

    /** Clear the per-request memo (tests / long-running workers). */
    public function flush(): void
    {
        $this->memo = [];
    }
}
