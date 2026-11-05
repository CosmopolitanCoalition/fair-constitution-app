<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Services\Node\NodeProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * W-0490 — the node profile in the operator area. Which node kits this box runs
 * and the scope of each. Page shell for any signed-in user; data and the save only
 * for an operator session (the same gate as /operator/operations).
 */
class NodeProfileController extends Controller
{
    public function show(NodeProfileService $profile): Response
    {
        $operator = Auth::guard('operator')->user();
        $authed = $operator !== null;

        return Inertia::render('Operator/NodeProfile', [
            'authed' => $authed,
            'operator' => $authed ? ($operator->username ?? null) : null,
            'profile' => $authed ? $profile->read() : null,
            'kits' => $authed ? [
                ['id' => 'individual', 'locked' => true],
                ['id' => 'household', 'locked' => false],
                ['id' => 'organization', 'locked' => false],
                ['id' => 'community', 'locked' => false],
            ] : [],
        ]);
    }

    public function save(Request $request, NodeProfileService $profile): RedirectResponse
    {
        $data = $request->validate([
            'hosted' => ['required', 'array'],
            'hosted.*' => ['string', 'in:'.implode(',', NodeProfileService::KITS)],
            'scopes' => ['nullable', 'array'],
            'scopes.*.scope' => ['nullable', 'string', 'max:500'],
            'scopes.*.mode' => ['nullable', 'in:'.implode(',', NodeProfileService::COMMUNITY_MODES)],
        ]);

        $operator = Auth::guard('operator')->user();

        try {
            $profile->save($data['hosted'], $data['scopes'] ?? [], $operator?->getAuthIdentifier() !== null ? (string) $operator->getAuthIdentifier() : null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['node_profile' => $e->getMessage()]);
        }

        return back()->with('status', 'node-profile:saved');
    }
}
