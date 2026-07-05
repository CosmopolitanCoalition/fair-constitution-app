<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * WI-4 smoke test — dev-route gating, deliberately DB-free (see
 * AuthPagesTest for the established posture: the container exports a real
 * APP_ENV=local, so these routes ARE registered during the test run, and
 * the DevToolsEnabled middleware's runtime config check is what we can
 * exercise without a database).
 *
 * The DB-backed flows (operator search, impersonate → shared props flip →
 * stop, ping simulation) are exercised against the running app via
 * curl/tinker — see the WI-4/WI-5 verification checklist.
 *
 * Production-env non-registration cannot be asserted in-process (the env
 * is fixed at boot); it is guaranteed by the route-registration condition
 * in routes/web.php (`app()->environment('local')`).
 */
class DevImpersonationTest extends TestCase
{
    public function test_dev_routes_404_when_the_toggle_is_off(): void
    {
        config(['cga.impersonation' => false]);

        $this->get('/dev/users')->assertNotFound();

        // CSRF skipped for the same reason as AuthPagesTest: the real
        // APP_ENV=local env var beats phpunit's <env>, so the middleware's
        // runningUnitTests() bypass never engages.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post('/dev/impersonate/stop')
            ->assertNotFound();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post('/dev/pings/simulate', ['days' => 5])
            ->assertNotFound();
    }

    public function test_dev_routes_require_authentication_when_enabled(): void
    {
        // Triple lock (WI-4 + game mode): local + SANDBOX world + toggle on.
        // A PRODUCTION world 404s even with the toggle on — dev tooling is a
        // sandbox-world property, not an ambient dev flag.
        \App\Support\GameMode::override(\App\Support\GameMode::PRODUCTION);
        $this->get('/dev/users')->assertNotFound();

        // In a SANDBOX world DevToolsEnabled passes, then the 'auth' middleware
        // bounces guests to /login — proving the gate order (404 gate, auth second).
        \App\Support\GameMode::override(\App\Support\GameMode::SANDBOX);
        $this->get('/dev/users')->assertRedirect('/login');

        \App\Support\GameMode::flush();
    }

    public function test_civic_routes_redirect_guests_to_login(): void
    {
        $this->get('/civic/residency')->assertRedirect('/login');

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post('/civic/pings')
            ->assertRedirect('/login');
    }
}
