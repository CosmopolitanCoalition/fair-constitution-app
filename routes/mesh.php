<?php

use App\Http\Controllers\MeshRoutingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public mesh-discovery routes (Phase G, G8b / C5)
|--------------------------------------------------------------------------
|
| Mounted under /api/mesh by bootstrap/app.php OUTSIDE the web group — NO
| session, NO CSRF, NO cookie. PUBLIC (a browser / CDN edge / travelling client),
| rate-limited as an anti-enumeration backstop. Exposes only the already-public
| G9 directory routing hints; never authority, never private data, never persists
| a supplied coordinate.
|
*/

// Where should this client enter the mesh? Nearest serving node to a picked
// jurisdiction, or to an opt-in (rounded, unstored) coordinate.
Route::get('/nearest', [MeshRoutingController::class, 'nearest']);
