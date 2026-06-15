<?php

namespace App\Services\Identity;

use RuntimeException;

/**
 * An attestation cannot be issued (Phase G, G-ID): the issuer is not the subject's
 * home authority, or the request is malformed. Carries a stable reason code.
 */
class AttestationRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
