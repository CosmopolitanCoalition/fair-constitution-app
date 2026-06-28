<?php

namespace App\Services\Matrix;

use RuntimeException;

/**
 * A cross-node voice-token mint was refused, fail-closed (Phase 5 — foci AV reach). Carries the HTTP status
 * the S2S controller returns. EVERY refusal path throws this and mints NOTHING — a token is issued only when
 * the home-vouched attestation verifies, the device signed THIS exact request, the request is fresh, and it
 * has not been replayed.
 */
class VoiceTokenRefused extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 403)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
