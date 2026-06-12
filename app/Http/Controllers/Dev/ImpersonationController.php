<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * WI-4 — dev impersonation (the mockups' demo-bar successor, §C5).
 *
 * LOCAL-ONLY: routes are registered solely when app()->environment('local')
 * and additionally 404-gated at runtime by DevToolsEnabled
 * (config('cga.impersonation')).
 *
 * Mechanics: the REAL account (which must be is_operator) keeps its id in
 * session 'impersonator_id' while the web guard logs in the target user.
 * Roles remain DERIVED — impersonation changes who you are, never what a
 * role grants. Starts/stops are appended to the audit chain (module 'dev')
 * for transparency; dev-only routes mean these entries never occur in
 * production.
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    public function __construct(
        private readonly AuditService $audit,
        private readonly RoleService $roles,
    ) {
    }

    /** GET /dev/users?q= — search by name/email for the dev bar (limit 20). */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'ilike', "%{$q}%")
                        ->orWhere('email', 'ilike', "%{$q}%");
                });
            })
            ->orderBy('created_at')
            ->limit(20)
            ->get(['id', 'name', 'display_name', 'email', 'is_operator']);

        return response()->json([
            'users' => $users->map(fn (User $user) => [
                'id'          => $user->id,
                'name'        => $user->name,
                'display_name' => $user->display_name,
                'email'       => $user->email,
                'is_operator' => $user->is_operator,
                'roles'       => $this->roles->rolesFor($user),
            ])->all(),
        ]);
    }

    /** POST /dev/impersonate/{user} — start viewing as the target user. */
    public function start(Request $request, User $user): Response
    {
        $real = $this->realUser($request);

        abort_unless($real?->is_operator === true, 403, 'Impersonation requires an operator account.');

        if ($user->is($real)) {
            // "Impersonating yourself" = just clear any active impersonation.
            return $this->stop($request);
        }

        $request->session()->put(self::SESSION_KEY, (string) $real->id);

        Auth::guard('web')->login($user);

        $this->audit->append(
            module: 'dev',
            event: 'impersonation.started',
            payload: [
                'operator_user_id'     => (string) $real->id,
                'impersonated_user_id' => (string) $user->id,
            ],
            actorId: (string) $real->id,
        );

        return $this->respond($request, [
            'impersonating' => ['active' => true, 'realName' => $real->name],
            'user'          => ['id' => $user->id, 'name' => $user->name],
        ]);
    }

    /** POST /dev/impersonate/stop — return to the real (operator) account. */
    public function stop(Request $request): Response
    {
        $impersonatorId = $request->session()->pull(self::SESSION_KEY);

        if ($impersonatorId !== null) {
            $real = User::find($impersonatorId);

            abort_if($real === null, 410, 'Impersonating account no longer exists.');

            $impersonated = $request->user();

            Auth::guard('web')->login($real);

            $this->audit->append(
                module: 'dev',
                event: 'impersonation.stopped',
                payload: [
                    'operator_user_id'     => (string) $real->id,
                    'impersonated_user_id' => $impersonated !== null ? (string) $impersonated->id : null,
                ],
                actorId: (string) $real->id,
            );
        }

        return $this->respond($request, ['impersonating' => false]);
    }

    // -------------------------------------------------------------------------

    /** The real human behind the session — the impersonator when active. */
    private function realUser(Request $request): ?User
    {
        $impersonatorId = $request->session()->get(self::SESSION_KEY);

        return $impersonatorId !== null
            ? User::find($impersonatorId)
            : $request->user();
    }

    private function respond(Request $request, array $payload): Response
    {
        return $request->expectsJson()
            ? response()->json($payload)
            : back();
    }
}
