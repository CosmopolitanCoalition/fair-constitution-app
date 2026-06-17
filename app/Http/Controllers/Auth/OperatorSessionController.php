<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Identity\OperatorIdentityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase G (G3c) — operator session login on the `auth:operator` guard, a SEPARATE
 * principal from the citizen `web` guard (the plane wall). Authenticates against
 * operator_accounts by username + password via OperatorIdentityService (LOCAL
 * only; the password never federates). Throttled 5/min like the citizen login.
 *
 * The two guards share one session store but different keys, so a human who is
 * both a citizen and an operator can hold both sessions at once; logout clears
 * ONLY the operator guard.
 */
class OperatorSessionController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly OperatorIdentityService $operators) {}

    /** GET /operator/login */
    public function create(): Response
    {
        return Inertia::render('Auth/OperatorLogin');
    }

    /** POST /operator/login */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::transliterate(Str::lower($data['username']).'|operator|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'username' => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
            ]);
        }

        $account = $this->operators->authenticate($data['username'], $data['password']);

        if ($account === null) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages(['username' => trans('auth.failed')]);
        }

        RateLimiter::clear($throttleKey);
        Auth::guard('operator')->login($account, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended('/federation');
    }

    /** POST /operator/logout — clears ONLY the operator guard (a citizen session, if any, survives). */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('operator')->logout();
        $request->session()->regenerateToken();

        return redirect('/federation');
    }
}
