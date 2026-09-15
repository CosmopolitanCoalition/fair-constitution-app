<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Auth\Concerns\RedeemsPendingInvite;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WI-3 — hand-rolled registration (no Breeze/Fortify).
 *
 * store() does NOT create the user directly: it files F-IND-001 through
 * the ConstitutionalEngine, whose handler creates the row inside the
 * engine transaction so every registration is sealed to the audit chain
 * (WF-SYS-04). The password is hashed HERE and travels only as
 * `password_hash` — raw credentials never enter the engine payload, and
 * the snapshot recorded to the chain carries no credential material.
 */
class RegisteredUserController extends Controller
{
    use RedeemsPendingInvite;

    /**
     * Languages accepted at registration — every code in THE locale registry
     * (config/locales.php), no longer a hand-copied five that had drifted. The
     * offered UI list is derived from the same registry on the JS side;
     * validation accepts every registered code so a newly registered locale is
     * never rejected at signup.
     *
     * @return list<string>
     */
    public static function languages(): array
    {
        return array_keys(config('locales.locales', []));
    }

    public function __construct(private readonly ConstitutionalEngine $engine)
    {
    }

    /** GET /register */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Register', $this->continuationProps($request));
    }

    /** POST /register */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'email'       => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password'    => ['required', 'confirmed', Rules\Password::defaults()],
            'terms'       => ['accepted'],
            'languages'   => ['sometimes', 'array', 'max:' . count(self::languages())],
            'languages.*' => ['string', Rule::in(self::languages())],
            'timezone'    => ['sometimes', 'nullable', 'string', 'timezone:all'],
        ], [
            'terms.accepted' => 'Confirm the terms to continue.',
        ]);

        $result = $this->engine->file('F-IND-001', null, [
            'name'          => $data['name'],
            'email'         => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'terms'         => true,
            'languages'     => $data['languages'] ?? ['en'],
            'timezone'      => $data['timezone'] ?? 'UTC',
        ]);

        $user = User::query()->findOrFail($result->recorded['user_id']);

        Auth::login($user);
        $request->session()->regenerate();

        // Redeem a pending invite (attribution) before honoring the destination — so a friend who
        // arrived via /i/{token} both gets credited to their inviter AND lands where they were headed.
        $this->redeemPendingInvite($request, $user);

        // WI-8: /civic is the default landing, but a carried destination (invite or auth-bounce) wins.
        return redirect()->intended('/civic')->with('status', 'Account created — your Individual record now exists.');
    }
}
