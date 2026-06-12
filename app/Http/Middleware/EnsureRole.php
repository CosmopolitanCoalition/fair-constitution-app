<?php

namespace App\Http\Middleware;

use App\Domain\Engine\Contracts\ResolvesRoles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WI-5 — 'role:R-xx' route middleware over the DERIVED role vocabulary
 * (RoleService; roles are never stored or granted — Art. I). Registered
 * as the 'role' alias in bootstrap/app.php; consumers arrive with later
 * phases (e.g. legislature routes requiring role:R-09).
 *
 * Multiple codes = any-of: role:R-03,R-09 passes when the user holds
 * either.
 */
class EnsureRole
{
    public function __construct(
        private readonly ResolvesRoles $roles,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$codes): Response
    {
        $held = $this->roles->rolesFor($request->user());

        abort_if(array_intersect($codes, $held) === [], 403, 'Missing required role: ' . implode(' or ', $codes));

        return $next($request);
    }
}
