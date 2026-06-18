<?php

use App\Domain\Engine\ConstitutionalViolation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Phase F — federation mesh endpoints, registered OUTSIDE the web group
        // (no session, no CSRF). Server-to-server; authenticated by Ed25519 peer
        // signature (VerifyPeerSignature) per route. Rate-limited as a backstop.
        then: function (): void {
            Route::prefix('api/federation')
                ->middleware('throttle:300,1')
                ->group(__DIR__.'/../routes/federation.php');

            // Phase G (G8b / C5) — public nearest-node routing, also OUTSIDE the web
            // group (stateless: no session, no CSRF, no cookie). Tighter throttle than
            // S2S as an anti-enumeration backstop; never persists a supplied coordinate.
            Route::prefix('api/mesh')
                ->middleware('throttle:60,1')
                ->group(__DIR__.'/../routes/mesh.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(
            prepend: [
                // Phase F — resolve the request locale BEFORE HandleInertiaRequests
                // so the shared `locale` prop and the blade <html lang/dir> agree
                // on first paint.
                \App\Http\Middleware\SetLocale::class,
            ],
            append: [
                \App\Http\Middleware\HandleInertiaRequests::class,
            ],
        );

        // WI-5: 'role:R-xx' over the derived role vocabulary (consumers
        // arrive with later phases).
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            // Phase F — Ed25519 peer-signature auth for /api/federation/*
            // (modes: public|tofu|pinned). NOT user/session auth.
            'federation.signed' => \App\Http\Middleware\VerifyPeerSignature::class,
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
                    'message' => $e->getMessage(),
                    'citation' => $e->citation,
                ], 422);
            }

            return back()->withErrors([
                'constitution' => $e->getMessage().' ('.$e->citation.')',
            ]);
        });
    })->create();
