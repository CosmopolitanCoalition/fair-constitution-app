<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\InstanceCapability;
use App\Models\PeerUpgradeProposal;
use App\Services\Federation\CapabilityProber;
use App\Services\Federation\CapabilityService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\MeshRoleGrantService;
use App\Services\PeerUpgradeAgreementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * mockups-v3-wiring Phase 4 — the operator/roles ACTIONS: thin HTTP wrappers over
 * the qualify → request → approve → join lifecycle, mirroring
 * app/Console/Commands/MeshRoleCommand.php verb-for-verb (same order of calls,
 * same guards). The controller wraps, it NEVER re-implements: qualification is
 * CapabilityProber, self-asserted channels are CapabilityService::registerSelf,
 * governed grants are MeshRoleGrantService, and consent is
 * PeerUpgradeAgreementService — a ConstitutionalViolation's message (with its
 * citation) is flashed back verbatim.
 *
 * All routes ride the auth:operator guard (the same wall as the
 * /operator/operations POSTs) — a citizen session cannot reach them.
 */
class MeshRolesController extends Controller
{
    /**
     * POST /operator/roles/qualify {capability, scope?} — run the prober
     * (capable-before-request; `mesh:role qualify`). Flashes the structured probe
     * result as `roles_probe` for the board to render.
     */
    public function qualify(Request $request, CapabilityProber $prober, InstanceIdentityService $identity): RedirectResponse
    {
        $validated = $request->validate([
            'capability' => ['required', Rule::in(InstanceCapability::CHANNELS)],
            'scope' => ['nullable', 'uuid'],
        ]);

        $identity->ensureIdentity();

        // Founding: every role is self-assertable, so qualification is moot —
        // report it qualified rather than probing against a jurisdiction that
        // may not exist yet.
        if (\App\Support\FoundingContext::isFounding()) {
            return back()->with('status', "[QUALIFIED] {$validated['capability']} — founding node self-asserts every role.");
        }

        $result = $prober->probe($validated['capability'], $this->scope($validated));

        if ($result['ok']) {
            return back()
                ->with('roles_probe', $result)
                ->with('status', "[QUALIFIED] {$validated['capability']} — {$result['detail']}");
        }

        return back()
            ->with('roles_probe', $result)
            ->withErrors(['roles' => "[NOT QUALIFIED] {$validated['capability']} — {$result['detail']}"]);
    }

    /**
     * POST /operator/roles/request {capability, scope?} — self-assert a free
     * channel, or open a governed role-grant request (`mesh:role request`).
     * Grants nothing — the dual-meter consent decides a governed channel.
     */
    public function request(Request $request, CapabilityService $caps, MeshRoleGrantService $grants, InstanceIdentityService $identity): RedirectResponse
    {
        $validated = $request->validate([
            'capability' => ['required', Rule::in(InstanceCapability::CHANNELS)],
            'scope' => ['nullable', 'uuid'],
        ]);
        $capability = $validated['capability'];

        $identity->ensureIdentity();

        try {
            // FOUNDING context: the operator is the sole constitutional authority
            // during setup — no seated government, no peer zones, often no
            // jurisdiction yet — so EVERY role is established directly (governed
            // included). Self-asserted channels register plainly; governed
            // channels get a SELF-SIGNED grant (Meter A with one operator =
            // self-attestation). This parallels the district-mapper's setup
            // context — "self-asserted like all other roles" (operator, 2026-07-05).
            if (\App\Support\FoundingContext::isFounding()) {
                if (InstanceCapability::isGoverned($capability)) {
                    $grants->selfGrantFounding($capability);
                } else {
                    $caps->registerSelf($capability);
                }

                return back()->with('status', "[ESTABLISHED] {$capability} (self-asserted — founding node).");
            }

            if (! InstanceCapability::isGoverned($capability)) {
                $caps->registerSelf($capability);

                return back()->with('status', "[ESTABLISHED] {$capability} (self-asserted — no consent needed).");
            }

            // Governed, post-founding: a grant attaches to a PLACE. If none
            // exists we say so plainly rather than crash on an empty-uuid lookup.
            $scope = $this->scope($validated);
            if ($scope === null) {
                return back()->withErrors(['roles' => 'A governed role attaches to a jurisdiction, and none exists yet. Finish founding first, or pass an explicit scope.']);
            }

            $proposal = $grants->request($capability, $scope);

            return back()->with('status', "[REQUESTED] {$capability} — proposal ".substr((string) $proposal->id, 0, 8).'…. The dual-meter consent decides; approve it from the pending list.');
        } catch (Throwable $e) {
            return back()->withErrors(['roles' => $e->getMessage()]);
        }
    }

    /**
     * POST /operator/roles/approve {proposal_id} — the bootstrap operator-board
     * path (`mesh:role approve`): record THIS operator's Meter A attestation when
     * the scope is unseated, then ratify. A seated government approves through the
     * MultiJurisdictionVote (Meter B), never this button — ratify() refuses with
     * the citation until that vote passes.
     */
    public function approve(Request $request, MeshRoleGrantService $grants, PeerUpgradeAgreementService $agreement, InstanceIdentityService $identity): RedirectResponse
    {
        $validated = $request->validate(['proposal_id' => ['required', 'uuid']]);

        $proposal = PeerUpgradeProposal::query()->find($validated['proposal_id']);
        if ($proposal === null || $proposal->kind !== PeerUpgradeProposal::KIND_ROLE_GRANT) {
            return back()->withErrors(['roles' => 'No such open role-grant request.']);
        }

        $identity->ensureIdentity();

        try {
            if ($agreement->applicableConsentLeg($proposal->affected_root_jurisdiction_id) === 'operator') {
                $operator = Auth::guard('operator')->user();
                if ($operator === null) {
                    return back()->withErrors(['roles' => 'No operator account to attest as (Meter A).']);
                }
                $agreement->recordOperatorConsent($proposal, $operator, true);
            }

            $ratified = $grants->ratify($proposal);

            return back()->with('status', "[GRANTED] {$ratified->capability} — channel enabled, grant minted ({$ratified->status}).");
        } catch (Throwable $e) {
            return back()->withErrors(['roles' => $e->getMessage()]);
        }
    }

    /** POST /operator/roles/revoke {capability} — drop one of OUR channels (`mesh:role revoke`; always unilateral). */
    public function revoke(Request $request, MeshRoleGrantService $grants, InstanceIdentityService $identity): RedirectResponse
    {
        $validated = $request->validate(['capability' => ['required', Rule::in(InstanceCapability::CHANNELS)]]);

        $identity->ensureIdentity();
        $dropped = $grants->revoke($validated['capability'], 'operator-revoked via operator console');

        return back()->with('status', $dropped
            ? "[DROPPED] {$validated['capability']}."
            : "No enabled channel {$validated['capability']} to drop.");
    }

    /**
     * The scope jurisdiction: the request's, else the root. Returns NULL when no
     * root jurisdiction exists yet (a founding node before map data) — callers
     * handle null instead of feeding an empty string into a uuid lookup, which
     * was the "invalid input syntax for type uuid" crash on Request.
     */
    private function scope(array $validated): ?string
    {
        $scope = (string) ($validated['scope'] ?? '');
        if ($scope !== '') {
            return $scope;
        }

        $root = DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->value('id');

        return $root !== null ? (string) $root : null;
    }
}
