<?php

use App\Domain\Engine\ConstitutionalViolation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // WI-5: 'role:R-xx' over the derived role vocabulary (consumers
        // arrive with later phases).
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);

        // WI-4: the dev-tools 404 gate must run BEFORE 'auth' (whose
        // CONTRACT sits in the default priority list and would otherwise
        // sort ahead of it) — a disabled toolset must be indistinguishable
        // from a missing route even for guests.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\DevToolsEnabled::class,
        );

        // WI-3 session auth: unauthenticated → /login; already-authenticated
        // hitting guest-only routes (login/register) → the civic dashboard
        // (WI-8 — /civic is the post-login landing).
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/civic');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Constitutional denials surface as 422s carrying their citation —
        // the engine has already recorded the rejected filing on the audit
        // chain before rethrowing (WF-SYS-04).
        $exceptions->render(function (ConstitutionalViolation $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message'  => $e->getMessage(),
                    'citation' => $e->citation,
                ], 422);
            }

            return back()->withErrors([
                'constitution' => $e->getMessage() . ' (' . $e->citation . ')',
            ]);
        });
    })->create();
