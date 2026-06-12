<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Engine\ConstitutionalEngine;
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
    /**
     * Languages offered at registration (mockup onboarding contract).
     * The production list grows to every supported locale with i18n.
     */
    public const LANGUAGES = ['en', 'es', 'ar', 'zh-Hans', 'hi'];

    public function __construct(private readonly ConstitutionalEngine $engine)
    {
    }

    /** GET /register */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /** POST /register */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'email'       => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password'    => ['required', 'confirmed', Rules\Password::defaults()],
            'terms'       => ['accepted'],
            'languages'   => ['sometimes', 'array', 'max:' . count(self::LANGUAGES)],
            'languages.*' => ['string', Rule::in(self::LANGUAGES)],
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

        // /civic is WI-8 — land on home with a flash until then.
        return redirect('/')->with('status', 'Account created — your Individual record now exists.');
    }
}
