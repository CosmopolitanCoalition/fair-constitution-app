<?php

namespace App\Http\Middleware;

use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Services\AuditService;
use App\Services\Federation\FederationClient;
use App\Services\Federation\InstanceIdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates server-to-server federation requests by Ed25519 signature
 * (Phase F). NOT session/user auth — peers are other instances, not browsers.
 *
 * Three modes (route argument):
 *   public  — only assert federation is enabled (e.g. GET /identity). No sig.
 *   tofu    — first-contact handshake: verify the signature against the public
 *             key carried in the request body (trust-on-first-use; the
 *             controller pins it). No peer row need exist yet.
 *   pinned  — (default) an established, trusted peer: look it up by server_id,
 *             verify against its PINNED public key, and bind it into the request.
 *
 * When federation is disabled the endpoints 404 — off is indistinguishable from
 * absent (the DevToolsEnabled posture).
 */
class VerifyPeerSignature
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(Request $request, Closure $next, string $mode = 'pinned'): Response
    {
        if (! (bool) InstanceSettings::current()->federation_enabled) {
            abort(404);
        }

        if ($mode === 'public') {
            return $next($request);
        }

        $serverId = (string) $request->header('X-Federation-Server-Id');
        $timestamp = (int) $request->header('X-Federation-Timestamp');
        $signature = (string) $request->header('X-Federation-Signature');

        if ($serverId === '' || $signature === '' || $timestamp === 0) {
            abort(401, 'Missing federation signature headers.');
        }

        $window = (int) config('cga.federation_replay_window', 300);
        if (abs(now()->timestamp - $timestamp) > $window) {
            abort(401, 'Federation request timestamp outside the replay window.');
        }

        $signingString = FederationClient::signingString(
            $request->getMethod(),
            $request->getRequestUri(),
            $timestamp,
            $request->getContent(),
        );

        if ($mode === 'tofu') {
            // First contact: verify against the body's public key; the
            // controller upserts/pins the peer on success.
            $publicKey = (string) $request->input('public_key');

            if ($publicKey === '' || ! InstanceIdentityService::verify($publicKey, $signingString, $signature)) {
                abort(401, 'Federation handshake signature invalid.');
            }

            return $next($request);
        }

        // pinned — an established, trusted peer.
        $peer = FederationPeer::query()->where('server_id', $serverId)->first();

        if ($peer === null || $peer->public_key === null || ! $peer->isTrusted()) {
            abort(401, 'Unknown or untrusted federation peer.');
        }

        if (! InstanceIdentityService::verify((string) $peer->public_key, $signingString, $signature)) {
            // A KNOWN peer presenting a bad signature is suspicious — chain it.
            $this->audit->append('federation', 'peer.signature_rejected', [
                'peer_server_id' => $serverId,
                'path' => $request->path(),
            ], 'WF-JUR-06', null, null, true, 'Peer signature verification failed.');

            abort(401, 'Federation peer signature invalid.');
        }

        $request->attributes->set('peer', $peer);

        return $next($request);
    }
}
