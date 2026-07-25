<?php

namespace App\Services;

/**
 * Phase I — the activation TIER CURVE (Art. II §1; owner ruling #15).
 *
 * Answers exactly one question: how many verified residents must a place have
 * before its government may BOOT?
 *
 *     threshold(P) = clamp(ceil(k · P^(1/exponent)), floor, cap)
 *
 * ── What this gates, and what it must never gate ──────────────────────────
 * The tier gates when a government may START. It NEVER gates the franchise.
 * Voting and candidacy are absolute on jurisdictional residency alone
 * (Art. I), and nothing in this class is ever consulted on a rights path.
 * A threshold that could make an honest community permanently unbootable
 * would be a franchise harm by another route — which is why `cap` is bounded
 * and why `floor` can never exceed it (see clampParams()).
 *
 * ── Two different cube roots. Do not merge them. ──────────────────────────
 * ActivationService::cubeRootSeats() SIZES A LEGISLATURE from population.
 * This class THRESHOLDS AN ACTIVATION from population. They are cousins, not
 * the same thing, and their defaults coincide numerically by accident:
 * 5 and 9 here are a resident floor and cap; 5 and 9 there are a seat floor
 * and a district ceiling. They must NOT share settings keys, and Earth is the
 * proof they are different functions — round(pop^⅓) = 1999 seats, while
 * threshold(pop) = 9 residents.
 *
 * ── Why the curve is deliberately flat ────────────────────────────────────
 * With the defaults, everything above 729 population lands on the cap. That
 * is intended: the threshold exists to stop a LONE ACTOR booting a government
 * over a place they do not live in, not to demand proportional enrolment. A
 * metropolis needing nine residents rather than nine thousand is the point.
 *
 * Pure statics, no DB, no facades — so the constitutional suite can pin the
 * curve without a schema, exactly as ActivationMathTest pins cubeRootSeats.
 * Resolution of the parameters from constitutional_settings lives in
 * ActivationService::thresholdFor().
 */
final class ActivationTierService
{
    /** Absolute floor on the resident floor — one person is not a community. */
    public const HARD_FLOOR = 1;

    /**
     * Absolute ceiling on the cap (Art. I rail). No ancestor — up to Earth —
     * may set a cap above this, because SettingsResolver takes the NEAREST
     * non-null ancestor and an unbounded cap would let one jurisdiction render
     * every descendant permanently unbootable.
     */
    public const HARD_CAP = 100;

    /** Shipping defaults; mirrors the mockup contract (social/legitimacy.html). */
    public const DEFAULTS = [
        'enabled'  => false,
        'k'        => 1,
        'exponent' => 3,
        'floor'    => 5,
        'cap'      => 9,
    ];

    private function __construct() {}

    /**
     * The curve. Population may be null or 0 (unmeasured / uninhabited
     * geodata) — both resolve to the floor, never to zero: an unknown
     * population must never make a place EASIER to boot than a known one.
     *
     * @param  array{enabled?:bool,k?:int|float,exponent?:int,floor?:int,cap?:int}  $params
     */
    public static function tierThreshold(int|float|null $population, array $params = []): int
    {
        $p = self::clampParams($params);

        // Tiers off = the dev posture: a single verified resident activates.
        if (! $p['enabled']) {
            return 1;
        }

        $pop = max((float) ($population ?? 0), 0.0);

        if ($pop <= 0.0) {
            return $p['floor'];
        }

        $raw = (int) ceil($p['k'] * pow($pop, 1.0 / $p['exponent']));

        return max($p['floor'], min($p['cap'], $raw));
    }

    /**
     * Normalise + bound the parameters. Bounds are a constitutional rail, not
     * defensive programming: they are what stops an ancestor setting a cap
     * that permanently disenfranchises a subtree.
     *
     * @param  array<string,mixed>  $params
     * @return array{enabled:bool,k:float,exponent:int,floor:int,cap:int}
     */
    public static function clampParams(array $params): array
    {
        $enabled  = (bool) ($params['enabled'] ?? self::DEFAULTS['enabled']);
        $k        = (float) ($params['k'] ?? self::DEFAULTS['k']);
        $exponent = (int) ($params['exponent'] ?? self::DEFAULTS['exponent']);
        $floor    = (int) ($params['floor'] ?? self::DEFAULTS['floor']);
        $cap      = (int) ($params['cap'] ?? self::DEFAULTS['cap']);

        // k < 1 would let a huge place boot on fewer residents than a small
        // one — the curve must be monotonic in population.
        $k = max($k, 0.0001);

        // exponent 0 would divide by zero; negative would invert the curve.
        $exponent = max($exponent, 1);

        $floor = max($floor, self::HARD_FLOOR);
        $cap   = min(max($cap, self::HARD_FLOOR), self::HARD_CAP);

        // A floor above the cap would make the clamp incoherent; the floor
        // yields, because raising the floor is the disenfranchising direction.
        $floor = min($floor, $cap);

        return [
            'enabled'  => $enabled,
            'k'        => $k,
            'exponent' => $exponent,
            'floor'    => $floor,
            'cap'      => $cap,
        ];
    }
}
