<?php

namespace App\Http\Controllers\Organizations;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Organizations\StaffTask;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrgStaffGrant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * IO-5 — the F-ORG-011 grant/revoke door (operator ruling 2026-09-13 ·
 * org-staff-delegation-model = A). Every write goes through the engine, so
 * grant and revoke are audited acts; the handler's agent-equality gate makes
 * both agent-only. The active-grant list rides on the org detail page as the
 * agent-only `delegations` prop (privacy — grantee identities).
 *
 *   GET    /organizations/{organization}/delegations          — the bounded list
 *   POST   /organizations/{organization}/delegations          — grant_task
 *   DELETE /organizations/{organization}/delegations/{grant}   — revoke_task
 */
class OrgDelegationController extends Controller
{
    public function __construct(private readonly ConstitutionalEngine $engine) {}

    /**
     * The agent's bounded page of active grants. It rides on the org detail
     * surface (the `delegations` prop, agent-gated there); this deep-link
     * re-renders that surface so the list and its cursor pages resolve.
     */
    public function index(Request $request, Organization $organization): mixed
    {
        abort_unless(
            $request->user() && (string) $organization->agent_user_id === (string) $request->user()->getKey(),
            403,
        );

        return app(OrganizationController::class)->show($request, $organization);
    }

    /** POST — F-ORG-011 'grant_task' (R-23). */
    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'grantee_user_id' => ['required', 'uuid'],
            'bucket'          => ['required', Rule::in(StaffTask::BUCKETS)],
            'reason'          => ['nullable', 'string', 'max:2000'],
        ]);

        $this->engine->file('F-ORG-011', $request->user(), [
            'action'          => 'grant_task',
            'organization_id' => (string) $organization->id,
            'grantee_user_id' => $validated['grantee_user_id'],
            'bucket'          => $validated['bucket'],
            'reason'          => $validated['reason'] ?? null,
        ]);

        return back()->with('status', __('Task delegated (F-ORG-011) — a scoped staff grant on the audit chain; it confers no constitutional office and you can revoke it.'));
    }

    /** DELETE — F-ORG-011 'revoke_task' (R-23). */
    public function destroy(Request $request, Organization $organization, string $grant): RedirectResponse
    {
        $row = OrgStaffGrant::query()
            ->where('organization_id', (string) $organization->id)
            ->find($grant);

        abort_if($row === null, 404);

        $this->engine->file('F-ORG-011', $request->user(), [
            'action'          => 'revoke_task',
            'organization_id' => (string) $organization->id,
            'grantee_user_id' => (string) $row->grantee_user_id,
            'bucket'          => (string) $row->task,
            'reason'          => $request->input('reason') ?: null,
        ]);

        return back()->with('status', __('Delegation revoked (F-ORG-011) — the person can no longer act for this organization.'));
    }
}
