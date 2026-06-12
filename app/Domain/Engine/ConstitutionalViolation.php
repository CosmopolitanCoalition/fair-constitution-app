<?php

namespace App\Domain\Engine;

use RuntimeException;

/**
 * Thrown when a filing breaches a constitutional rule (hardened or
 * bounds-checked). Carries the constitutional citation so rejections are
 * recorded — and surfaced to users — with their legal basis, e.g.
 * "legislature_max_seats 9→12 blocked: exceeds hardened ceiling (Art. II §2)".
 *
 * The ConstitutionalEngine catches this, appends a rejected=true entry to
 * the audit chain (rejections are first-class chain entries, WF-SYS-04),
 * and rethrows for the HTTP layer to render as a 422.
 */
class ConstitutionalViolation extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $citation,
    ) {
        parent::__construct($message);
    }
}
