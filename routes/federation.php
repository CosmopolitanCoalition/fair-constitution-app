<?php

use App\Http\Controllers\Federation\FlipController;
use App\Http\Controllers\Federation\PeerController;
use App\Http\Controllers\Federation\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Federation mesh routes (Phase F, WF-JUR-06)
|--------------------------------------------------------------------------
|
| Server-to-server endpoints, mounted under /api/federation by bootstrap/app.php
| OUTSIDE the web group — NO session, NO CSRF. Authenticated by Ed25519
| peer signature (VerifyPeerSignature, alias `federation.signed`), not by user
| session. Modes: public (identity only), tofu (first-contact handshake),
| pinned (an established trusted peer).
|
| F2 adds /audit-tail, /sync, /checkpoint; F3 adds /flip — all `pinned`.
|
*/

// Public identity — how a peer first learns our server_id + public key.
Route::get('/identity', [PeerController::class, 'identity'])
    ->middleware('federation.signed:public');

// First contact — a peer presents its identity (trust-on-first-use).
Route::post('/handshake', [PeerController::class, 'handshake'])
    ->middleware('federation.signed:tofu');

// Liveness — established peers only (CLK-20).
Route::post('/heartbeat', [PeerController::class, 'heartbeat'])
    ->middleware('federation.signed');

// ── Full Faith & Credit sync (F2) — established peers only ────────────────
Route::get('/audit-tail', [SyncController::class, 'auditTail'])
    ->middleware('federation.signed');
Route::post('/sync', [SyncController::class, 'receive'])
    ->middleware('federation.signed');
Route::get('/checkpoint', [SyncController::class, 'checkpoint'])
    ->middleware('federation.signed');

// ── Authority flip (F3) — a peer hands us a partition subtree ─────────────
Route::post('/flip', [FlipController::class, 'receive'])
    ->middleware('federation.signed');
