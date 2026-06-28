<?php

namespace App\Services\Matrix;

use RuntimeException;

/**
 * The capable peer refused (or could not serve) a forwarded cross-node voice-token request (Phase 5 — foci
 * AV reach, L side). Carries the peer's HTTP status so the player endpoint can surface it. Distinct from
 * NoReachableHolder (which means no peer hosts voice.sfu at all → degrade to no voice).
 */
class VoiceReachFailed extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 502)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
