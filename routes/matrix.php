<?php

use App\Http\Controllers\Matrix\AppServiceController;
use Illuminate\Support\Facades\Route;

// Phase K-3 — the CGA appservice AS-API (Synapse → the in-Laravel appservice). Registered OUTSIDE the
// web group (no session, no CSRF) and behind VerifyMatrixAppService (hs_token). nginx routes
// /_matrix/app/ here (a more-specific location than the /_matrix/ → Synapse proxy). userId / alias
// carry ':' (and URL-encoded '#') so they use a permissive constraint.
Route::put('/_matrix/app/v1/transactions/{txnId}', [AppServiceController::class, 'transactions']);
Route::get('/_matrix/app/v1/users/{userId}', [AppServiceController::class, 'users'])->where('userId', '.*');
Route::get('/_matrix/app/v1/rooms/{alias}', [AppServiceController::class, 'rooms'])->where('alias', '.*');

// Legacy unstable transaction path (older Synapse).
Route::put('/_matrix/app/unstable/transactions/{txnId}', [AppServiceController::class, 'transactions']);
