<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Guest locale read-back (gap lane fix-guest-locale, 2026-09-15). A guest's
 * header language choice had nowhere to land server-side. persistLocale wrote
 * localStorage but SetLocale never read that key, so the choice was lost on a
 * full reload. This endpoint records the choice in the session under the SAME
 * key SetLocale resolves ('locale'), so the next request renders the chosen
 * locale in both the Inertia `locale` prop and the blade <html lang/dir>.
 *
 * No auth. A guest is exactly who needs this. An authenticated viewer files
 * the choice through POST /civic/record/profile, which updates the user row.
 * The code is validated against the ENABLED product locales — the same set
 * SetLocale negotiates into — so an unknown or display-only code is rejected
 * (422) rather than stored and then silently ignored by SetLocale::isSupported.
 *
 * Returns a 303 redirect back, NOT a bare 204. persistLocale posts here through
 * the Inertia router, and the Inertia client fails any response that lacks the
 * x-inertia header by showing a full-screen error modal (@inertiajs/core 2.3.x,
 * Response::handleNonInertiaResponse -> fireInvalidEvent -> modal). A 204 carries
 * no such header. A redirect-back is followed transparently to a fresh GET that
 * carries the Inertia header, so no modal fires — the same shape POST
 * /civic/record/profile returns for the authenticated branch.
 */
class LocaleController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(SetLocale::supported())],
        ]);

        if ($request->hasSession()) {
            $request->session()->put('locale', $validated['locale']);
        }

        return back(303);
    }
}
