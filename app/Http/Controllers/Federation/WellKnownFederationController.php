<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Services\Federation\FederationDiscoveryService;
use Illuminate\Http\JsonResponse;

/**
 * GET /.well-known/cga-federation (PUBLIC, no auth) — the zero-foreknowledge discovery descriptor.
 *
 * How a cold node (or the canonical front door, e.g. worldofstatecraft.org) self-identifies as a CGA
 * federation entry point. Public facts only — the SAME identity exposed at /api/federation/identity,
 * plus the self URL + reachable endpoints. NEVER a secret (no Cloudflare token, no join key, no
 * box-specific instruction). Advisory: it only LOCATES a federation; admission stays the signed
 * adopt handshake, where authority is actually checked.
 */
class WellKnownFederationController extends Controller
{
    public function __construct(private readonly FederationDiscoveryService $discovery) {}

    public function descriptor(): JsonResponse
    {
        return response()->json($this->discovery->describeSelf());
    }
}
