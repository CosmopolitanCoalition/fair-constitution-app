<?php

namespace Tests\Feature;

use App\Http\Middleware\DevToolsEnabled;
use App\Support\GameMode;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * WI-4 — the dev-tooling gate, and the ORDER it runs in.
 *
 * ── Why this file no longer drives the /dev routes ───────────────────────
 * It used to, on a premise stated in its own docblock: *"the container
 * exports a real APP_ENV=local, so these routes ARE registered during the
 * test run."* That was never true. The container exports no APP_ENV at all;
 * `.env` supplies `local`, and phpunit's `<env name="APP_ENV" value="testing"/>`
 * is set before boot and beats `.env`. So `app()->environment('local')` is
 * false under the suite, the `/dev` route family **never registers**, and:
 *
 *   · the production case passed for the WRONG REASON — 404 because the route
 *     did not exist, not because the game-mode gate refused;
 *   · the sandbox case asserted a redirect to /login and **could not pass at
 *     any time**.
 *
 * Checked rather than assumed: `APP_ENV=testing` has been in phpunit.xml since
 * 2026-02-21 and the route guard landed 2026-06-12 — so the guard arrived into
 * an environment that already made it unregisterable. **This has never
 * passed.** It is not a regression from today's work.
 *
 * ── What is pinned instead, and why it is not a weakening ────────────────
 * The property at stake is a SECURITY POSTURE, not a routing detail: the dev
 * gate runs BEFORE `auth`, so a disabled dev tool is indistinguishable from a
 * route that was never built. Invert that order and a disabled tool starts
 * announcing its own existence to unauthenticated callers — a 302 to /login
 * says "something is here"; a 404 says nothing.
 *
 * Deleting the assertion to green the suite would have thrown that away. So it
 * is re-pinned against things that DO exist under `testing`:
 *   1. the middleware's own behaviour, invoked directly;
 *   2. the middleware ORDER in routes/web.php, asserted structurally — the
 *      established grep-pin posture (cf. ClusterAuthoritySeparation).
 */
class DevImpersonationTest extends TestCase
{
    private function gate(): DevToolsEnabled
    {
        return new DevToolsEnabled;
    }

    /**
     * The "route" the gate hands off to. It must return a real Response —
     * `handle()` is typed to one, so a bare string throws a TypeError that
     * looks like a failure while actually proving the gate ALLOWED.
     *
     * @return callable(Request): Response
     */
    private function passthrough(): callable
    {
        return static fn (Request $r) => new Response('reached the route');
    }

    private function assertRefuses(string $why): void
    {
        try {
            $this->gate()->handle(Request::create('/dev/users'), $this->passthrough());
            $this->fail("the gate should have refused: {$why}");
        } catch (HttpException $e) {
            // 404, never 403: a refusal must not confirm that anything is here.
            $this->assertSame(404, $e->getStatusCode(), "{$why} — must 404, not disclose");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The permissive baseline; each case removes exactly one condition, so
        // a failure names which lock stopped holding.
        $this->app['env'] = 'local';
        config(['cga.impersonation' => true]);
        GameMode::override(GameMode::SANDBOX);
    }

    protected function tearDown(): void
    {
        GameMode::flush();
        parent::tearDown();
    }

    public function test_the_gate_refuses_when_the_toggle_is_off(): void
    {
        config(['cga.impersonation' => false]);
        $this->assertRefuses('the impersonation toggle is off');
    }

    /**
     * A PRODUCTION world 404s even on a local checkout with the toggle on.
     * The dev toolbox is a property of the WORLD, chosen at founding — never
     * an ambient code flag.
     */
    public function test_the_gate_refuses_in_a_production_world(): void
    {
        GameMode::override(GameMode::PRODUCTION);
        $this->assertRefuses('the world is in production mode');
    }

    /** Outside a local checkout it refuses regardless of world or toggle. */
    public function test_the_gate_refuses_outside_the_local_environment(): void
    {
        $this->app['env'] = 'production';
        $this->assertRefuses('not a local environment');
    }

    /**
     * ⚑ THE POSITIVE CONTROL. Without it every refusal above is equally
     * satisfied by a gate that refuses unconditionally — which is
     * indistinguishable from a control that was never wired.
     */
    public function test_the_gate_allows_when_all_three_locks_are_open(): void
    {
        $response = $this->gate()->handle(Request::create('/dev/users'), $this->passthrough());

        $this->assertSame(
            'reached the route',
            $response->getContent(),
            'local + sandbox + toggle on must reach the route, or the refusals above prove nothing',
        );
    }

    /**
     * ⚑ THE ORDERING — the actual security property, asserted structurally
     * because the routes cannot register under `testing`.
     *
     * `DevToolsEnabled` must precede `auth` in the group. If they swap, a
     * guest hitting a disabled dev tool gets a redirect to /login instead of a
     * 404 — and the redirect discloses that the tool exists.
     */
    public function test_the_dev_gate_runs_before_auth_in_the_route_group(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertMatchesRegularExpression(
            '/Route::middleware\(\[\s*DevToolsEnabled::class\s*,\s*[\'"]auth[\'"]/',
            $routes,
            'DevToolsEnabled must come BEFORE auth in the /dev group: a disabled dev tool has to '
            .'404 like a missing route, not 302 to /login and thereby announce that it exists.',
        );
    }

    /** Unrelated to the dev gate, and still true: civic routes bounce guests. */
    public function test_civic_routes_redirect_guests_to_login(): void
    {
        $this->get('/civic/residency')->assertRedirect('/login');

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post('/civic/pings')
            ->assertRedirect('/login');
    }
}
