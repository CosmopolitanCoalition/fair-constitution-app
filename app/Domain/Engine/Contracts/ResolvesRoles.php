<?php

namespace App\Domain\Engine\Contracts;

use App\Models\User;

/**
 * Role derivation contract consumed by the ConstitutionalEngine when
 * authorizing a filing against a handler's declared role requirement.
 *
 * Roles are DERIVED, never stored (Art. I — R-01..R-04 are pure functions
 * of facts; office roles R-06..R-30 have authoritative seat rows). Phase A
 * binds StubRoleResolver (R-01 for any authenticated user); the real
 * RoleService lands in WI-5 and replaces the binding in
 * ConstitutionProvider without touching the engine.
 */
interface ResolvesRoles
{
    /**
     * Role codes currently held by the user (e.g. ['R-01', 'R-02']).
     * Null actor = system; returns [].
     *
     * @return list<string>
     */
    public function rolesFor(?User $user): array;
}
