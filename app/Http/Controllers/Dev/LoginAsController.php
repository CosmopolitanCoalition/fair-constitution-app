<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Dev login-as — POST /dev/login-as {user_id|email}.
 *
 * LOCAL-ONLY (the WI-4 gate: registered only in app()->environment('local')
 * and 404-gated by DevToolsEnabled when config('cga.impersonation') is
 * off — identical posture to /dev/impersonate and /dev/residency/grant).
 *
 * Establishes a real authenticated web session for the named user WITHOUT
 * a password — the operator-driven equivalent of clicking a persona in the
 * mockups' demo bar. It exists so the operator can drive the live HTTP
 * walkthrough as many distinct members (the chamber needs ~8 individual
 * voters to reach quorum) without a password per persona. Roles stay
 * DERIVED; the switch is appended to the audit chain (module 'dev') for
 * transparency. Production never registers this route.
 */
class LoginAsController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->resolve($request);

        abort_if($user === null, 404, 'No such user.');

        $previous = $request->user();

        // login() already migrates the session (the framework rotates the id
        // and persists the auth id); a second regenerate() here would rotate
        // AGAIN after the response cookie is queued, orphaning the jar's id.
        Auth::guard('web')->login($user);

        $this->audit->append(
            module: 'dev',
            event: 'login_as.switched',
            payload: [
                'target_user_id'   => (string) $user->id,
                'previous_user_id' => $previous !== null ? (string) $previous->id : null,
            ],
            actorId: (string) $user->id,
        );

        return response()->json([
            'user' => ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    private function resolve(Request $request): ?User
    {
        $id    = $request->input('user_id');
        $email = $request->input('email');

        if (is_string($id) && $id !== '') {
            return User::query()->find($id);
        }

        if (is_string($email) && $email !== '') {
            return User::query()->where('email', $email)->first();
        }

        return null;
    }
}
