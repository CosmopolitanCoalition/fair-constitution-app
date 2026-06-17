<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\FederationPeer;
use App\Services\Federation\ReadWriteRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase G (G3c) — a pinned mirror submits a read-write petition for a jurisdiction
 * subtree (S2S). Recorded as a read_write_requests intake; GRANTING is the
 * governed flow (Art. V §7 via G6 / the de-facto operator board via G-VER) and
 * rides the existing authority-flip machinery — never this endpoint. Raw signed
 * bytes via getContent(); federation.signed:pinned.
 */
class ReadWriteController extends Controller
{
    public function __construct(private readonly ReadWriteRequestService $rw) {}

    public function request(Request $request): JsonResponse
    {
        /** @var FederationPeer $peer */
        $peer = $request->attributes->get('peer');

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $rootJurisdictionId = (string) ($body['root_jurisdiction_id'] ?? '');

        if ($rootJurisdictionId === '') {
            return response()->json(['error' => 'root_jurisdiction_id is required'], 422);
        }

        $req = $this->rw->submit(
            (string) $peer->server_id,
            $peer->public_key !== null ? (string) $peer->public_key : null,
            $rootJurisdictionId,
            isset($body['note']) ? (string) $body['note'] : null,
        );

        return response()->json([
            'status'     => 'received',
            'request_id' => $req->id,
            'state'      => $req->status,
        ]);
    }
}
