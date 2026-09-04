<?php

namespace App\Services\Districting;

use RuntimeException;

/**
 * The leaf blade search hit its wall-clock cap mid-cut (operator ruling
 * 2026-09-03, the Tumaco grind: a coastal scope whose blades are each slow
 * spends minutes inside one findBlade angle sweep, where the call-count budget
 * never trips because it only decrements between nodes).
 *
 * DISTINCT from NoContiguousCut on purpose: the subdivide recursion catches
 * NoContiguousCut at every tier to backtrack, so a NoContiguousCut thrown mid
 * findBlade would be retried, not bailed. This exception is a sibling
 * RuntimeException that NO recursion catch intercepts, so it unwinds straight
 * to planWithFallback, whose RuntimeException arm routes the scope to the next
 * template — the box, the general fallback.
 */
class BladeBudgetExhausted extends RuntimeException
{
}
