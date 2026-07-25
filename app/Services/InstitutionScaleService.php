<?php

namespace App\Services;

/**
 * How much institution a jurisdiction is entitled to (operator ruling 2026-07-25).
 *
 * "A small town does not need a giant rotunda with seventeen court systems."
 * Complexity follows three things: how many PEOPLE are there, how many PARTS
 * the place has, and how DEEP the structure goes.
 *
 * ── The zero rule ────────────────────────────────────────────────────────
 * A jurisdiction with no people gets NOTHING. Not a stub, not a court, not a
 * square. Empty boundary rows are geodata, not polities, and provisioning
 * them would put ~34,700 uninhabited villages' worth of institutions in the
 * database for nobody to use.
 *
 * ── Why this is not a constitutional gate ────────────────────────────────
 * This decides what a provisioning ENGINE materialises in advance. It never
 * decides whether a place MAY govern itself: the moment a real resident
 * verifies, CLK-06 activates the jurisdiction through the ordinary path
 * (ActivationService) and the full set appears. Nothing here gates a right,
 * a vote or a candidacy (Art. I), and nothing here is consulted on a rights
 * path. It is a materialisation policy, and the tier curve
 * (ActivationTierService) remains the thing that gates a BOOT.
 *
 * ── The binding ──────────────────────────────────────────────────────────
 * Under `free` binding (instance_settings.population_binding) population
 * imposes nothing and every jurisdiction is entitled to its full set — the
 * tabletop group, the single-org adopter, the small world. Under `real` the
 * scale below applies. That choice is a founding world property, not a
 * legislative setting.
 *
 * Pure statics, no DB — pinnable without a schema, like cubeRootSeats and
 * ActivationTierService.
 */
final class InstitutionScaleService
{
    public const BINDING_REAL = 'real';
    public const BINDING_FREE = 'free';

    /**
     * Institution tiers. Names describe the SHAPE of the polity, not a rank —
     * a hamlet is not a lesser government, it is a smaller one.
     */
    public const TIER_NONE       = 'none';        // no people: no institutions
    public const TIER_MINIMAL    = 'minimal';     // a village
    public const TIER_STANDARD   = 'standard';    // a town or small city
    public const TIER_EXTENDED   = 'extended';    // a large city or province
    public const TIER_FULL       = 'full';        // a nation or the planet

    /** Population thresholds, ascending. Policy dials, not constitutional law. */
    private const BANDS = [
        self::TIER_MINIMAL  => 1,
        self::TIER_STANDARD => 1_000,
        self::TIER_EXTENDED => 250_000,
        self::TIER_FULL     => 10_000_000,
    ];

    private function __construct() {}

    /**
     * The tier a jurisdiction sits in.
     *
     * @param  int|null  $population   real population; null/0 = uninhabited
     * @param  int       $constituents direct child jurisdictions ("parts")
     * @param  string    $binding      instance_settings.population_binding
     */
    public static function tierFor(
        ?int $population,
        int $constituents = 0,
        string $binding = self::BINDING_REAL,
    ): string {
        // A world free of real-world numbers entitles everything to a full
        // standard set — the tabletop / single-org / small-world case.
        if ($binding === self::BINDING_FREE) {
            return self::TIER_STANDARD;
        }

        $pop = max((int) ($population ?? 0), 0);

        // THE ZERO RULE. But "parts" count as complexity in their own right:
        // a jurisdiction whose population reads 0 while it holds real
        // constituents is a geodata artefact (the raster missed it), not an
        // empty place — it still needs somewhere for its constituents to meet.
        if ($pop < 1) {
            return $constituents > 0 ? self::TIER_MINIMAL : self::TIER_NONE;
        }

        $tier = self::TIER_MINIMAL;

        foreach (self::BANDS as $name => $floor) {
            if ($pop >= $floor) {
                $tier = $name;
            }
        }

        // Depth/parts promote by one band: a place that contains many places
        // is structurally more complex than its headcount alone suggests.
        if ($constituents >= 25) {
            $tier = self::promote($tier);
        }

        return $tier;
    }

    /** Does this jurisdiction get institutions materialised at all? */
    public static function provisions(
        ?int $population,
        int $constituents = 0,
        string $binding = self::BINDING_REAL,
    ): bool {
        return self::tierFor($population, $constituents, $binding) !== self::TIER_NONE;
    }

    /**
     * Judicial bench size for a tier.
     *
     * Art. IV §1 floors every bench at 5 and imposes no ceiling. Larger
     * polities seat more judges; the constituent-per-judge rule in
     * JudiciaryFormationService remains the law for jurisdictions that hold
     * constituents, and this is the floor a bench starts from.
     */
    public static function judgeCount(string $tier, int $minJudges = 5): int
    {
        $scaled = match ($tier) {
            self::TIER_NONE     => 0,
            self::TIER_MINIMAL  => $minJudges,
            self::TIER_STANDARD => $minJudges,
            self::TIER_EXTENDED => $minJudges + 2,
            self::TIER_FULL     => $minJudges + 4,
        };

        return $scaled === 0 ? 0 : max($minJudges, $scaled);
    }

    /**
     * Civic spaces a tier is entitled to.
     *
     * The public square is NEVER scaled away from an inhabited place — it is
     * where people speak, and gating speech on headcount is the Art. I error.
     * Halls open on seatedness, not on size.
     *
     * @return list<string>
     */
    public static function spaceTypes(string $tier): array
    {
        return $tier === self::TIER_NONE ? [] : ['public_square', 'halls'];
    }

    private static function promote(string $tier): string
    {
        return match ($tier) {
            self::TIER_MINIMAL  => self::TIER_STANDARD,
            self::TIER_STANDARD => self::TIER_EXTENDED,
            default             => self::TIER_FULL,
        };
    }
}
