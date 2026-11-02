<?php

namespace App\Http\Middleware;

use App\Support\ApiKeys;
use Closure;
use Illuminate\Http\Request;

/** Bearer-key auth for /api/v1. Usage: api.key:calendar:read. Binds the key's owner as the request user. */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next, string $scope)
    {
        // A signed-in browser session (the workspace screens) acts as its own user with every scope.
        // Any bearer header is still checked as a key, so a key can never borrow a session.
        if (! $request->bearerToken() && $request->hasSession() && $request->user()) {
            return $next($request);
        }
        $key = ApiKeys::find((string) $request->bearerToken());
        abort_unless($key, 401, 'Missing or invalid API key.');
        abort_unless(in_array($scope, json_decode($key->scopes, true) ?: [], true), 403, 'This key lacks the '.$scope.' scope.');
        $user = \App\Models\User::query()->find($key->user_id);
        abort_unless($user && $user->status !== 'closed', 401, 'Account unavailable.');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_key_id', $key->id);

        return $next($request);
    }
}
