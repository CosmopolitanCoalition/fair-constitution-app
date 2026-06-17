<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Services\Mirror\MirrorJoinKeyService;
use App\Services\Mirror\MirrorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Phase G (G3c) — the HOST side of cluster adoption, in the browser (the GUI
 * counterpart to the cluster:keys / cluster:approve CLI). Gated by the
 * `auth:operator` guard (the operator plane). One shared MirrorService /
 * MirrorJoinKeyService path with the CLI.
 *
 * The minted plaintext key rides a ONE-SHOT flash (never an Inertia prop, never
 * persisted), so a refresh loses it — matching `cluster:keys:mint` "shown only
 * once" and the Argon2id-hash-at-rest contract.
 */
class FederationHostController extends Controller
{
    /** POST /federation/host/keys — mint an invite key; plaintext shown ONCE. */
    public function mintKey(Request $request, MirrorJoinKeyService $keys): RedirectResponse
    {
        $data = $request->validate([
            'max_uses'        => ['nullable', 'integer', 'min:1', 'max:100'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $expiresAt = ! empty($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null;

        [$plaintext] = $keys->mint((int) ($data['max_uses'] ?? 1), $expiresAt);

        return back()
            ->with('minted_key', $plaintext)
            ->with('status', 'Invite key minted — copy it now; it is shown only once.');
    }

    /** POST /federation/host/keys/revoke — revoke an invite key by its handle. */
    public function revokeKey(Request $request, MirrorJoinKeyService $keys): RedirectResponse
    {
        $data = $request->validate(['handle' => ['required', 'string', 'max:64']]);

        $keys->revoke($data['handle']);

        return back()->with('status', "Revoked invite key {$data['handle']}.");
    }

    /** POST /federation/host/requests/{id}/approve — vouch an applicant in as a read-only mirror. */
    public function approveRequest(string $id, MirrorService $mirror): RedirectResponse
    {
        try {
            $mirror->approveRequest($id);
        } catch (\Throwable $e) {
            return back()->withErrors(['request' => $e->getMessage()]);
        }

        return back()->with('status', 'Approved — the applicant is admitted as a read-only mirror (authoritative for nothing).');
    }

    /** POST /federation/host/requests/{id}/reject */
    public function rejectRequest(string $id, MirrorService $mirror): RedirectResponse
    {
        try {
            $mirror->rejectRequest($id);
        } catch (\Throwable $e) {
            return back()->withErrors(['request' => $e->getMessage()]);
        }

        return back()->with('status', 'Rejected the adoption request.');
    }
}
