<?php

namespace App\Services\Districting;

use RuntimeException;

/**
 * A line-split plan REFUSAL — the plan could not be computed (no raster, no
 * in-band grouping, non-convex strip failure) or the client's previewed
 * plan_hash no longer matches the recompute. These are the operator-facing
 * 422s of the autoseed commit path; any OTHER RuntimeException escaping the
 * filing transaction is an internal fault and must keep surfacing as a 500,
 * never leak its message to the client (review finding, 2026-07-17).
 */
class PlanRefused extends RuntimeException
{
}
