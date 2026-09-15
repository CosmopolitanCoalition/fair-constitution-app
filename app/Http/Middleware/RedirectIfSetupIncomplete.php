<?php

namespace App\Http\Middleware;

use App\Models\InstanceSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setup lock — until the wizard finishes (instance_settings.setup_completed_at),
 * every web navigation is pinned to the setup flow. This stops the operator from
 * wandering into /civic, /legislatures, the home dashboard, etc. before the
 * world exists (the reported "I can navigate away from bootstrapping").
 *
 * Allowed through while setup is incomplete: the setup surfaces themselves, the
 * operator plane (legitimately part of bootstrap), and the auth routes. Pre-schema
 * (no instance_settings table yet) everything passes — the bootstrap page handles
 * that state. Once setup completes, this is a no-op.
 *
 * Server-side, so it holds even if the client JS is bypassed (the old Home.vue
 * onMounted redirect was client-only).
 */
class RedirectIfSetupIncomplete
{
    /**
     * PAGE prefixes reachable during setup.
     *
     * Two kinds live here. First, the wizard spine: the wizard itself, the
     * operator console, the district mapper + jurisdiction viewer (step 3 /
     * step 2 tools), the federation join screen, and the auth routes.
     *
     * Second, the public read surfaces (operator ruling 2026-09-10: every
     * page is readable by anyone, a role gates actions never the page). A read
     * page stays reachable while a box is still building, the way a citizen may
     * watch the machinery. Only the top-level prefix is listed; every API/XHR/
     * tile is let through by the wantsHtml gate below.
     *
     * Reads only. Every write door stays gated by its own auth + operator
     * checks, never by this list.
     */
    private const ALLOW = [
        // Wizard spine + auth. 'register' is deliberately NOT here: while setup
        // is incomplete no citizen account may be minted on a public box, so a
        // guest hitting /register lands on the wizard. The founder account is
        // created through /setup/bootstrap, never /register. Once setup
        // completes this middleware is a no-op and /register opens normally.
        'setup', 'operator', 'legislatures', 'jurisdictions', 'federation', 'login', 'logout',
        // Public read surfaces (operator ruling 2026-09-10).
        'simworld', 'building', 'economy', 'executives', 'departments', 'committees',
        'learn', 'support', 'videos', 'launchpad', 'tour', 'atlas', 'coverage', 'coverage-ops', 'system',
        'organizations', // W-0444: the registry and the profiles read for a guest
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // The lock is an interactive browser-navigation guard. Console context
        // (artisan + the test suite, which drives simulated requests through the
        // kernel) is not navigation — bypass it. runningInConsole() is the
        // reliable signal here: the container exports a real APP_ENV=local, so
        // runningUnitTests() (which keys on env=testing) never engages.
        if (app()->runningInConsole()) {
            return $next($request);
        }

        // Gate ONLY top-level page navigations (a browser document load or an
        // Inertia visit). Every API/XHR (JSON), map tile (image), and asset passes
        // untouched — the wizard's steps legitimately call a lot of them (the
        // cosmic-address picker, the mapper's jurisdiction/raster/heartbeat/probe
        // endpoints, backup import/export, the join sync-progress poll). Gating
        // those would 302 them to an HTML page and break the step. So we gate the
        // page load, never the data behind it.
        $wantsHtml = $request->header('X-Inertia')
            || str_contains((string) $request->header('Accept'), 'text/html');
        if (! $wantsHtml || ! $request->isMethod('GET')) {
            return $next($request);
        }

        if ($this->allowed($request)) {
            return $next($request);
        }

        try {
            if (! Schema::hasTable('instance_settings')) {
                return $next($request); // pre-schema — bootstrap page owns this state
            }
            $settings = InstanceSettings::query()->first();
            if ($settings === null || $settings->isSetupComplete()) {
                return $next($request);
            }
        } catch (\Throwable $e) {
            return $next($request); // never hard-fail a request on an uncertain read
        }

        // Setup incomplete → into the wizard. A 302 works for both a hard browser
        // navigation and an Inertia visit (Inertia follows it to the /setup page).
        return redirect('/setup');
    }

    private function allowed(Request $request): bool
    {
        $path = $request->path(); // 'civic', 'setup/mode', '/' → ''

        // Root is the guest cover page (Home). It is a public read surface, so
        // it stays reachable while a box is still building (operator ruling
        // 2026-09-10). request()->path() returns '' for '/', which matches no
        // prefix below, so admit it here.
        if ($path === '' || $path === '/') {
            return true;
        }

        foreach (self::ALLOW as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }
        return false;
    }
}
