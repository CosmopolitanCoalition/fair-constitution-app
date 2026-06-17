<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\OperatorAccount;
use App\Services\Identity\MeshOperatorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Phase G (G-OP / G3c) — Flow B: link THIS operator account to an EXISTING mesh
 * identity by device-POSSESSION proof. A web action on the ARRIVING instance,
 * gated `auth:operator` (the traveling operator is logged in locally here). The
 * proof is a signature from an already-bound, ACTIVE device of the mesh identity
 * (verified against the gossip-learned bindings) — NEVER a password. All
 * verification + binding lives in MeshOperatorService::linkByProof; this is the
 * thin session-authed front door the proof string targets (`POST /operator/link`).
 */
class OperatorLinkController extends Controller
{
    public function link(Request $request, MeshOperatorService $mesh): RedirectResponse
    {
        $data = $request->validate([
            'mesh_operator_id'      => ['required', 'uuid'],
            'new_device_public_key' => ['required', 'string', 'max:255'],
            'timestamp'             => ['required', 'integer'],
            'proof_signature_b64'   => ['required', 'string', 'max:255'],
        ]);

        /** @var OperatorAccount $account */
        $account = Auth::guard('operator')->user();

        // You may only bind a device you have enrolled on THIS account — the link
        // joins your own local device to the mesh identity, not an arbitrary key.
        $enrolled = $account->devices()->whereNull('revoked_at')
            ->where('device_public_key', $data['new_device_public_key'])->exists();

        if (! $enrolled) {
            return back()->withErrors(['link' => 'That device is not enrolled on this operator account — enrol it here before linking.']);
        }

        try {
            $mesh->linkByProof(
                $account,
                $data['mesh_operator_id'],
                $data['new_device_public_key'],
                (int) $data['timestamp'],
                $data['proof_signature_b64'],
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['link' => $e->getMessage()]);
        }

        return back()->with('status', 'Linked this operator account to the mesh identity by device-possession proof.');
    }
}
