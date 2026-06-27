<?php

namespace App\Services\Federation;

use RuntimeException;

/**
 * A trusted-broker credential share was refused, fail-closed (Identity Broker — roles campaign Phase 4).
 * Carries the HTTP status the S2S controller returns. EVERY refusal path throws this and stores NOTHING —
 * a credential is only persisted when every gate (authenticated pinned sender, explicit per-domain accept
 * opt-in, seal-opens-to-us, inner names this exact sender + this box + this domain) passes.
 */
class BrokerShareRefused extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
