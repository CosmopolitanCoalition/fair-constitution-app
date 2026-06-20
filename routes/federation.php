<?php

use App\Http\Controllers\Federation\AdoptionController;
use App\Http\Controllers\Federation\CertGrantController;
use App\Http\Controllers\Federation\CertRequestController;
use App\Http\Controllers\Federation\FlipController;
use App\Http\Controllers\Federation\GeodataController;
use App\Http\Controllers\Federation\MeshOperatorController;
use App\Http\Controllers\Federation\PeerController;
use App\Http\Controllers\Federation\ReadWriteController;
use App\Http\Controllers\Federation\RoleGrantController;
use App\Http\Controllers\Federation\SyncController;
use App\Http\Controllers\Federation\UpgradeConsentController;
use App\Http\Controllers\Federation\WriteController;
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

// ── Operational bundle (G5/G5a) — the DATA half of a governed authority flip: a
// pinned peer delivers the subtree's per-election keys SEALED to us; we open them
// (only we can) and re-wrap each under our KEK, fail-closed. The manifest half
// rides /flip; this carries what must never touch the routine sync tail.
Route::post('/flip/operational', [FlipController::class, 'receiveOperational'])
    ->middleware('federation.signed');

// ── Join-key adoption (G2) — a would-be mirror presents a join key (tofu) ──
Route::post('/adopt', [AdoptionController::class, 'adopt'])
    ->middleware('federation.signed:tofu');

// ── Forwarded write (G4) — a pinned peer forwards a write we are authoritative
// for; executed through the NORMAL ConstitutionalEngine, recorded exactly-once.
Route::post('/write', [WriteController::class, 'write'])
    ->middleware('federation.signed');

// ── Operator-identity gossip (G-OP-2) — a pinned peer announces a mesh operator
// identity + its signed device-key bindings; we ingest what we can authenticate
// against each binding's bound-by server's pinned key.
Route::post('/operator/announce', [MeshOperatorController::class, 'announce'])
    ->middleware('federation.signed');

// ── Read-write petition (G3c) — a pinned mirror asks to become a read-write peer
// for a jurisdiction subtree. Recorded as an intake; granting is the governed
// flow (G6 / G-VER), never this endpoint.
Route::post('/request-read-write', [ReadWriteController::class, 'request'])
    ->middleware('federation.signed');

// ── Upgrade consent (G-VER / A2) — a co-affected pinned peer delivers its Meter C
// mesh consent for one of our open upgrade proposals. Standing (authoritative for a
// jurisdiction in the affected subtree) is enforced by the service, not the route.
Route::post('/upgrade/consent', [UpgradeConsentController::class, 'store'])
    ->middleware('federation.signed');

// ── Broker channel (Mesh Roles ★9/★11) — a pinned peer asks an in-mesh broker for a
// TLS cert (signed request → the same Broker::issue as Box C, authority_keys from the
// gossiped broker_authorizations); /cert-grant delivers an authority's minted cert_grant
// to the grantee (verified against the authority's OWN pinned key, never the relayer's).
Route::post('/cert-request', [CertRequestController::class, 'certRequest'])
    ->middleware('federation.signed');
Route::post('/cert-grant', [CertGrantController::class, 'receiveGrant'])
    ->middleware('federation.signed');
// Mesh Roles ★17 — cross-instance JOIN: an authority delivers a minted capability_grant to the
// grantee; we apply it ONLY if it verifies against the authority's pinned key + names this box.
Route::post('/role-grant', [RoleGrantController::class, 'receiveGrant'])
    ->middleware('federation.signed');

// ── Geodata manifest (G3c, N3) — a pinned peer pulls our signed dataset manifest.
// Large/license-bound rasters ride this SEPARATE channel, never the audit tail;
// each manifest is signed by its origin (verified against the origin's pinned key).
Route::get('/geodata/manifest', [GeodataController::class, 'manifest'])
    ->middleware('federation.signed');
