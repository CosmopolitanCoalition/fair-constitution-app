<?php

namespace App\Domain\Counting;

/**
 * Scaled-integer ("microvote") arithmetic for the counting engine.
 *
 * 1 vote = 1,000,000 µv (SCALE). All tallies, weights, surpluses, and
 * transfers are PHP 64-bit integers in µv. Truncation is toward zero
 * (operands are never negative, so truncation == floor). Floats are
 * banned from the count: IEEE-754 rounding is platform- and
 * order-sensitive, which would make `record_hash` non-deterministic —
 * a constitutional bug (PHASE_B_DESIGN_counting_engine.md §B.2).
 *
 * The single overflow-prone operation is the Gregory transfer product
 * `weight × surplus` (up to 10⁶ × ~3×10¹³ > 2⁶³ at Earth-district
 * scale); mulDiv() routes that one product through bcmath strings.
 * Everything else stays native int.
 */
final class Micro
{
    /** Microvotes per whole vote (fixed-point scale, 6 decimal places). */
    public const SCALE = 1_000_000;

    private function __construct()
    {
    }

    /**
     * floor(a × b ÷ c) for non-negative a, b and positive c, exact at
     * arbitrary magnitude. Native fast path when the product cannot
     * overflow; bcmath (explicit scale 0, no bcscale() global state)
     * otherwise.
     */
    public static function mulDiv(int $a, int $b, int $c): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        if ($c <= 0) {
            throw new \InvalidArgumentException('Micro::mulDiv divisor must be positive.');
        }

        if ($a <= intdiv(PHP_INT_MAX, $b)) {
            return intdiv($a * $b, $c);
        }

        return (int) \bcdiv(\bcmul((string) $a, (string) $b), (string) $c, 0);
    }
}
