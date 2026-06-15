<?php

namespace App\Services\Identity;

use App\Models\User;

/**
 * The request-scoped bridge that carries a VERIFIED forwarded actor's attested
 * role snapshot to the engine (Phase G, G-ID). AttestedForwardedActor sets it
 * after verifying a forwarded write's attestation; AttestationGate reads it so the
 * engine authorizes that one write against the attested roles instead of
 * re-deriving from residency facts the leader does not hold; WriteRouterService
 * clears it once the filing completes.
 *
 * It is set ONLY on a verified forwarded-write path. Local session users never
 * touch it, so role resolution for them is byte-identical to the live derivation
 * (Art. I). One subject at a time — a forwarded write carries exactly one actor.
 */
class AttestedActorContext
{
    private ?string $subjectUserId = null;

    /** @var list<string> */
    private array $roles = [];

    /**
     * @param  list<string>  $roles  the role codes the verified attestation carries
     */
    public function set(string $subjectUserId, array $roles): void
    {
        $this->subjectUserId = $subjectUserId;
        $this->roles = array_values($roles);
    }

    /**
     * The attested roles for $user IFF they are the current forwarded subject;
     * null otherwise (so the caller falls back to live derivation).
     *
     * @return list<string>|null
     */
    public function attestedRolesFor(User $user): ?array
    {
        if ($this->subjectUserId === null) {
            return null;
        }

        return (string) $user->getKey() === $this->subjectUserId ? $this->roles : null;
    }

    public function clear(): void
    {
        $this->subjectUserId = null;
        $this->roles = [];
    }
}
