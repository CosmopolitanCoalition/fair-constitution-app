<?php

namespace App\Services\Identity;

use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Models\User;
use App\Services\RoleService;

/**
 * The role-resolution gate for G-ID (Phase G). It IS a `ResolvesRoles` so it can
 * stand in for `RoleService` in the engine binding — but for a LOCAL (session)
 * user it NEVER short-circuits: it delegates straight to the live `RoleService`
 * derivation (Art. I — roles are a pure function of facts, never stored).
 *
 * The ATTESTED path — returning a forwarded actor's attested role snapshot — is a
 * SEPARATE entry point consulted only on `attested`-mode (forwarded-write)
 * requests, wired by VerifyActorAttestation + the WriteRouter (G4). Binding this
 * gate in place of RoleService is therefore a zero-behavior-change dual-stack
 * step: local session users resolve exactly as before.
 */
class AttestationGate implements ResolvesRoles
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly AttestedActorContext $context,
    ) {}

    /**
     * Local users: derive live — never the attestation, never a stored snapshot.
     * The ONE exception is a verified FORWARDED-write subject: the leader does not
     * hold their residency facts, so it authorizes that single write against the
     * attested role snapshot AttestedForwardedActor placed in the request context.
     */
    public function rolesFor(?User $user): array
    {
        if ($user !== null) {
            $attested = $this->context->attestedRolesFor($user);

            if ($attested !== null) {
                return $attested;
            }
        }

        return $this->roles->rolesFor($user);
    }

    /**
     * The attested path: the role codes carried by a verified forwarded actor's
     * attestation. Used ONLY by the forwarded-write path, never for local users.
     *
     * @param  list<string>  $attestedRoles
     * @return list<string>
     */
    public function attestedRolesFor(array $attestedRoles): array
    {
        return array_values($attestedRoles);
    }
}
