<?php

namespace App\Domain\Engine\Contracts;

use App\Models\User;

/**
 * Resolves the local actor a FORWARDED write (Phase G, G4) should file as.
 *
 * Dual-stack, mirroring ResolvesRoles → AttestationGate:
 *   - SystemOnlyForwardedActor (now) — admits only system-scoped forwards;
 *     a forward claiming a citizen actor is REFUSED, because their standing
 *     cannot be verified across instances without a G-ID attestation;
 *   - AttestedForwardedActor (later) — verifies a G-ID attestation and returns
 *     the bound local User.
 *
 * Returning null files as the system (jobs/clocks posture). Throwing
 * ForwardedWriteRefused rejects the forward outright.
 */
interface ResolvesForwardedActor
{
    /**
     * @param  array<string,mixed>  $envelope  the decoded forwarded-write body
     *
     * @throws \App\Services\Federation\ForwardedWriteRefused when the claimed actor cannot be honoured
     */
    public function resolve(array $envelope): ?User;
}
