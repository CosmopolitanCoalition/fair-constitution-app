<?php

namespace App\Services\Federation;

use RuntimeException;

/**
 * Mesh Roles ★21 — no mesh peer currently hosts (and is reachable for) a live-service capability the local
 * node lacks. The SAFE-DEGRADE signal: the caller drops the feature gracefully (text-only / "voice
 * unavailable here") and NEVER fails hard. "Features-off is a fallback posture, not a gameplay tier" — a
 * missing live service never refuses governance sync, it just isn't offered locally right now.
 */
class NoReachableHolder extends RuntimeException
{
    public function __construct(public readonly string $capability)
    {
        parent::__construct(
            "No reachable mesh peer hosts [{$capability}] — degrade safely (this live feature is unavailable here)."
        );
    }
}
