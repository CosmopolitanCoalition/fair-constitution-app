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
     * The bench a tier's court starts from: the floor. THE BENCH LAW
     * (operator ruling 2026-09-05, bench-scaling-law B) sizes every bench
     * from the chamber's Type A seats and the floor setting — see
     * App\Support\BenchLaw. The 5/7/9 tier bumps are retired; a tier no
     * longer adds judges. Kept so callers that reason per tier (the zero rule)
     * still get 0 for `none` and the floor for everything else.
     */
    public static function judgeCount(string $tier, int $minJudges = 5): int
    {
        return $tier === self::TIER_NONE ? 0 : max(1, $minJudges);
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

    // ── The service-scale formula (SERVICE_SCALE_FORMULA.md, lane 4 C3,
    //    operator-signed-off 2026-07-29, all §9 calls option (a)) ────────────
    //
    // Four pure statics, keeping this class's DB-free posture. They size a
    // THIRD thing — institution COUNTS — distinct from the two cube roots
    // (cubeRootSeats sizes a legislature; ActivationTierService sizes a boot
    // threshold). They NEVER gate a right, a vote, or a candidacy (Art. I) and
    // are read only while a place is pre-governance: the instant a real chamber
    // seats, its counts are whatever it votes and the formula gets out of the
    // way (the handoff, §6).

    /**
     * Legislative committee TARGET for a chamber of $seats (§4.4).
     *
     *   K(S) = clamp( round(3.5 + 2.7·ln S), 1, round(S/5) )
     *
     * A DEMO-POSTURE / pre-governance TARGET, never a row a provisioning engine
     * writes: committees are ACTS OF SELF-GOVERNMENT (F-LEG-009), formed by a
     * seated chamber. This is the number the sim drives toward and the
     * pre-governance ceiling — it never overrides a governed chamber's choice.
     * The round(S/5) clamp keeps a tiny chamber from having more committees
     * than it can staff (~5 members each); the log term binds for large
     * chambers. Hits the real anchors exactly: US House 435→20, Senate 100→16.
     */
    public static function committeeTarget(int $seats): int
    {
        if ($seats < 1) {
            return 0;
        }

        $curve    = (int) round(3.5 + 2.7 * log($seats));
        $staffCap = max(1, (int) round($seats / 5)); // ~5 members per committee

        return max(1, min($curve, $staffCap));
    }

    /**
     * Executive department TARGET for a place of $population (§4.4).
     *
     *   D(P) = clamp( round(-7.8 + 1.67·ln P), 3, 30 )
     *
     * DEMO-POSTURE / pre-governance TARGET only — departments arrive through
     * F-EXE-001 once a chamber is seated (an act of self-government). Floor 3 (a
     * minimal executive still needs a few functions), cap 30 (top of the
     * observed core-cabinet band; a chamber may vote itself more — the formula
     * is a demo default, not a limit). Uninhabited places get nothing (the zero
     * rule). Fits the 5–38 sublinear cabinet band.
     */
    public static function departmentTarget(?int $population): int
    {
        $pop = max((int) ($population ?? 0), 0);

        if ($pop < 1) {
            return 0; // the zero rule — an uninhabited place gets nothing
        }

        $curve = (int) round(-7.8 + 1.67 * log($pop));

        return max(3, min($curve, 30));
    }

    /**
     * Extra neighbourhood civic rooms for a LOCAL place (§4.5).
     *
     *   extra_rooms(P) = clamp( floor(P / 50000), 0, 20 )   — local tier ONLY.
     *
     * square + halls are unconditional for every inhabited place (Art. I, see
     * spaceTypes()); these sit ON TOP of those, and ONLY for a local/leaf
     * jurisdiction. The jurisdiction tree carries the aggregate, so Earth's node
     * earns 0 extra — its 7.99B ÷ 50k of rooms belong to its descendants, not
     * the planet node. Anchored to 1 civic centre per ~50,000 (Q5 ruling (a)),
     * capped at 20.
     *
     * Q4 ruling (a): a provisioning engine MAY materialise these directly — a
     * civic room is not an act of self-government.
     */
    public static function extraRooms(?int $population, bool $isLocal): int
    {
        if (! $isLocal) {
            return 0; // the tree distributes rooms to descendants, not the node
        }

        $pop = max((int) ($population ?? 0), 0);

        return max(0, min(intdiv($pop, 50_000), 20));
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
