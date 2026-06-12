<?php

namespace App\Domain\Engine;

use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Models\User;

/**
 * Phase A placeholder: every authenticated user holds R-01 (Individual)
 * and nothing else. Replaced by the real derivation service (RoleService,
 * WI-5: R-02 from active residency claims, R-03/R-04 from active
 * jurisdiction associations) via the ConstitutionProvider binding.
 */
class StubRoleResolver implements ResolvesRoles
{
    public function rolesFor(?User $user): array
    {
        return $user === null ? [] : ['R-01'];
    }
}
