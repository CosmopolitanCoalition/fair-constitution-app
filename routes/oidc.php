<?php

use App\Http\Controllers\Oidc\OidcDiscoveryController;
use App\Http\Controllers\Oidc\OidcTokenController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Game-as-OIDC-provider — STATELESS endpoints (Phase 5 / K-3, K3-C)
|--------------------------------------------------------------------------
|
| The machine-to-machine half of the OIDC provider MAS (configured upstream→game)
| consumes. Mounted OUTSIDE the web group (no session, no CSRF, no cookie) — the
| same posture as the federation / appservice S2S routes: discovery + JWKS are
| public reads; the token endpoint is authenticated by the client secret + PKCE in
| OidcProviderService, never a session. The /oauth/authorize endpoint is the ONLY
| OIDC route that needs a session (the citizen's login) and lives in routes/web.php.
|
*/

Route::get('/.well-known/openid-configuration', [OidcDiscoveryController::class, 'configuration']);
Route::get('/oauth/jwks', [OidcDiscoveryController::class, 'jwks']);
Route::post('/oauth/token', [OidcTokenController::class, 'token']);
Route::match(['get', 'post'], '/oauth/userinfo', [OidcTokenController::class, 'userinfo']);
