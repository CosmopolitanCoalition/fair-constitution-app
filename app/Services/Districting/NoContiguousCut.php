<?php

namespace App\Services\Districting;

use RuntimeException;

/**
 * Thrown by findBlade when its angle sweep is GEOMETRICALLY exhausted for a
 * given seat ratio — no straight blade splits the region into two contiguous
 * in-band pieces. Distinct from an infrastructure failure (a QueryException
 * from a transient DB/PostGIS error, which also extends RuntimeException): the
 * Tier-1 lawful-split fallback in subdivide() catches ONLY this sentinel, so a
 * transient DB hiccup on the balanced blade (e.g. during the pg-crash breaker
 * pause) propagates up to the template ladder untouched instead of silently
 * diverting a balanced-valid scope onto a non-balanced fallback cut.
 */
class NoContiguousCut extends RuntimeException
{
}
