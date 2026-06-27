<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\FederationPeer;
use App\Services\Federation\BrokerFailoverService;
use App\Services\Federation\BrokerShareRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Identity Broker — trusted-broker credential failover (roles campaign Phase 4). A primary broker pushes a
 * SEALED per-domain Cloudflare credential to a designated failover broker here, so the failover can issue
 * certs + write DNS if the primary is down. Reached only by a pinned peer (federation.signed); the
 * authenticated peer ($request->attributes['peer']) — NOT a body field — is the sender of record.
 *
 * All the security is in BrokerFailoverService::receiveShare (open the seal; bind it to this box + this
 * authenticated sender + the per-domain accept opt-in; never overwrite a local credential). This controller
 * just maps a fail-closed refusal to its HTTP status and NEVER echoes the token.
 */
class BrokerCredentialShareController extends Controller
{
    public function __construct(private readonly BrokerFailoverService $failover) {}

    public function receive(Request $request): JsonResponse
    {
        /** @var FederationPeer $peer */
        $peer = $request->attributes->get('peer');

        $body = json_decode((string) $request->getContent(), true);
        $body = is_array($body) ? $body : [];

        try {
            $result = $this->failover->receiveShare($peer, $body);
        } catch (BrokerShareRefused $e) {
            return response()->json(['error' => $e->getMessage()], $e->status());
        }

        // NEVER echo the token — domain + provenance only.
        return response()->json([
            'ok' => true,
            'stored' => true,
            'domain' => $result['domain'],
        ]);
    }
}
