<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * WI-3 smoke test — auth surface + shared-prop contract, deliberately
 * DB-free (the phpunit 'testing' connection is sqlite :memory:; users +
 * audit_log are Postgres-only and RefreshDatabase is forbidden on the
 * live dev DB). The DB-backed flows (register → engine → audit row →
 * login/logout/throttle) are exercised against the running app via
 * curl/tinker — see the WI-3 verification checklist.
 */
class AuthPagesTest extends TestCase
{
    public function test_register_page_renders_for_guests(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Register'));
    }

    public function test_login_page_renders_for_guests(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    }

    public function test_guest_shared_props_follow_the_c3_contract(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user', null)
            ->where('auth.roles', ['R-00'])
            ->where('auth.impersonating', false)
            ->has('instance.name')
            ->has('instance.setupComplete')
            ->has('flash')
            ->where('locale', 'en'));
    }

    public function test_logout_requires_authentication(): void
    {
        // The container exports APP_ENV=local as a real process env var, so
        // phpunit's <env APP_ENV=testing> cannot override it and the CSRF
        // middleware's runningUnitTests() bypass never engages — skip CSRF
        // explicitly; the assertion under test is the auth guest redirect.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post('/logout')
            ->assertRedirect('/login');
    }
}
