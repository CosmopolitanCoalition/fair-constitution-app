<?php

namespace App\Services\Mirror;

use RuntimeException;

/**
 * A host refuses a mirror's adoption (Phase G, G2): an invalid/exhausted join
 * key, a self-adopt, or an incomplete request. Carries the HTTP status the
 * AdoptionController maps it to. Distinct from a replay, which surfaces as the
 * `(applicant_server_id, nonce)` unique violation (→ 409).
 */
class AdoptionRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $status = 403,
    ) {
        parent::__construct($reason);
    }
}
