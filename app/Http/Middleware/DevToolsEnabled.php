<?php

namespace App\Http\Middleware;

use App\Support\GameMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WI-4 — gate for the /dev/* tooling routes (impersonation, ping simulator,
 * dev board-seat). Triple lock:
 *
 *  1. the routes are only REGISTERED when app()->environment('local')
 *     (see routes/web.php) — they do not exist in a production image build;
 *  2. the world must be in SANDBOX game mode. The dev toolbox is a WORLD
 *     property chosen at founding, never an ambient code flag: a production
 *     world running on a local checkout still gets 404s here. This is the
 *     principled replacement for "dev tools appear whenever APP_ENV=local"
 *     ([[feedback_no_dev_exceptions_and_test_discipline]]); it also fixes the
 *     first-run complaint that dev tooling showed BEFORE sandbox was chosen —
 *     until game_mode is set, this returns 404;
 *  3. config('cga.impersonation') must be on, so the toggle takes effect
 *     immediately (and is testable without re-booting the app).
 *
 * Runs BEFORE 'auth' in the route group so a disabled toolset is
 * indistinguishable from a missing route even for guests.
 */
class DevToolsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        // Cheap flags first (env + config), the DB-backed game-mode read last, so
        // a disabled toggle short-circuits without touching the database.
        abort_unless(
            app()->environment('local')
                && config('cga.impersonation', true)
                && GameMode::isSandbox(),
            404
        );

        return $next($request);
    }
}
