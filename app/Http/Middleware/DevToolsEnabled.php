<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WI-4 — gate for the /dev/* tooling routes (impersonation, ping
 * simulator). Double lock:
 *
 *  1. the routes are only REGISTERED when app()->environment('local')
 *     (see routes/web.php) — they do not exist in production;
 *  2. this middleware additionally 404s them at runtime unless
 *     config('cga.impersonation') is on, so the toggle takes effect
 *     immediately (and is testable without re-booting the app).
 *
 * Runs BEFORE 'auth' in the route group so a disabled toolset is
 * indistinguishable from a missing route even for guests.
 */
class DevToolsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            app()->environment('local') && config('cga.impersonation', true),
            404
        );

        return $next($request);
    }
}
