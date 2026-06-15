<?php

namespace App\Services\Federation;

use RuntimeException;

/**
 * The authoritative leader refuses a forwarded write (Phase G, G4): a forward
 * claiming an unverifiable citizen actor (pre-G-ID), a forward misdirected to a
 * jurisdiction we are NOT authoritative for, or a malformed envelope. Carries
 * the HTTP status the WriteController maps it to.
 *
 * Distinct from a constitutional REJECTION — a forward that reaches the engine
 * and is validly denied is an `executed=rejected` outcome (422 with a citation),
 * recorded idempotently like any other; this exception is the forward never
 * reaching the engine at all.
 */
class ForwardedWriteRefused extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $status = 422,
    ) {
        parent::__construct($reason);
    }
}
