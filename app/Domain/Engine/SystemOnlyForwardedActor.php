<?php

namespace App\Domain\Engine;

use App\Domain\Engine\Contracts\ResolvesForwardedActor;
use App\Models\User;
use App\Services\Federation\ForwardedWriteRefused;

/**
 * Pre-G-ID forwarded-actor policy (Phase G, G4). Only SYSTEM-scoped forwards are
 * honoured: a forward whose envelope carries no actor claim files as the system
 * (null actor), exactly as a job or clock handler does.
 *
 * A forward that claims a citizen actor is REFUSED — a person's standing
 * (residency → derived rights, Art. I, never stored, never replicated) cannot be
 * verified on a peer without a signed G-ID attestation. Honouring a bare
 * "actor" claim would let a forwarding instance assert anyone's identity. The
 * AttestedForwardedActor swap (one binding line in ConstitutionProvider) turns
 * citizen forwarding on once the G-ID attestation surface is wired.
 */
class SystemOnlyForwardedActor implements ResolvesForwardedActor
{
    public function resolve(array $envelope): ?User
    {
        if (($envelope['actor'] ?? null) !== null) {
            throw new ForwardedWriteRefused(
                'citizen write-forwarding requires a verifiable G-ID attestation (not yet enabled)',
                403,
            );
        }

        return null; // system filing
    }
}
