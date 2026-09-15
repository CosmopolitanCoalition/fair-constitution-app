<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Gap lane fix-guest-locale, follow-up (1) — GUEST LOCALE READ-BACK.
 *
 * A guest's header language choice used to write only localStorage, which
 * SetLocale never read, so the choice was lost on reload. The choice now posts
 * to /locale (LocaleController), which stores it in the session under the key
 * SetLocale resolves ('locale'). The next request then renders that locale.
 *
 * DB-free: the phpunit 'testing' connection is sqlite :memory: (phpunit.xml).
 * A guest GET / touches no schema (Schema::hasTable guards instanceProps and
 * the session guard makes no query without an auth cookie), so no migration or
 * live PostgreSQL connection is needed. CSRF is skipped because the container
 * exports APP_ENV=local, which defeats phpunit's runningUnitTests() bypass
 * (same posture as AuthPagesTest).
 */
class GuestLocaleTest extends TestCase
{
    public function test_guest_post_locale_stores_an_enabled_code_in_the_session(): void
    {
        // The endpoint returns a 303 redirect back, NOT a 204. persistLocale posts
        // here through the Inertia router, which shows a full-screen error modal on
        // any response with no x-inertia header (a 204 has none); a redirect is
        // followed to a fresh Inertia GET, so no modal fires. This asserts the
        // Inertia-valid response shape and the session write together.
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/locale', ['locale' => 'es'])
            ->assertStatus(303)
            ->assertSessionHas('locale', 'es');
    }

    public function test_set_locale_resolves_the_stored_guest_choice(): void
    {
        // The choice recorded by POST /locale lives in the session under 'locale'.
        // SetLocale reads exactly that key and sets app()->getLocale(), which the
        // blade root renders as <html lang="es">. This asserts SetLocale's read
        // contract directly against a started session carrying the key.
        //
        // FLAG (confirmed empirically 2026-09-15, fix is out of this lane's file
        // list): in the live web stack SetLocale sorts to index 0 of the web
        // group, ahead of StartSession at index 3 (verified by running
        // Illuminate\Routing\SortedMiddleware over the bootstrapped web group with
        // the kernel's middlewarePriority: SetLocale 0, EncryptCookies 1,
        // AddQueuedCookiesToResponse 2, StartSession 3). SetLocale is prepended to
        // the web group in bootstrap/app.php and is absent from the priority list,
        // so it stays ahead of the prioritized StartSession. At handle() time no
        // session is started, hasSession() is false, and this guest-session branch
        // never fires for a real request. The read-back becomes effective only once
        // SetLocale is ordered AFTER StartSession (a one-line move in
        // bootstrap/app.php, which is not in this lane's file list). This test pins
        // the read contract SetLocale owns; the ordering fix is escalated to the
        // desk to widen scope.
        $session = $this->app['session']->driver('array');
        $session->put('locale', 'es');

        $request = Request::create('/', 'GET');
        $request->setLaravelSession($session);

        $response = (new SetLocale())->handle($request, function () {
            return response($this->app->getLocale());
        });

        $this->assertSame('es', $response->getContent(), 'SetLocale resolves the session locale key');
    }

    public function test_guest_post_locale_rejects_an_unknown_code(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/locale', ['locale' => 'zz-not-a-locale'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('locale');
    }
}
