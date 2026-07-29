<?php

namespace App\Http\Controllers\Civic;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Services\ResidencyService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WI-8 — GET /civic/identity: minimal identity-verification surface
 * (identity-verification contract).
 *
 * Phase A ships only the manual attestation-request stub (F-IND-004) —
 * no external ID bridge (Phase F), no officer console. The page's most
 * important element is the banner: verification is NEVER a rights
 * requirement (Art. I); skipping is always allowed.
 */
class IdentityVerificationController extends Controller
{
    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly ResidencyService $residency,
        private readonly \App\Services\RoleService $roles,
    ) {
    }

    public function show(Request $request): Response
    {
        $user = $request->user();

        // The latest accepted F-IND-004 filing, if any — lets the page show
        // "requested, pending attestation" across visits without a table.
        $latestRequest = AuditEntry::query()
            ->where('actor_user_id', (string) $user->id)
            ->where('ref', 'F-IND-004')
            ->where('rejected', false)
            ->orderByDesc('seq')
            ->first();

        $claim = $this->residency->openClaimFor($user);

        // Where the viewer stands on the ESM-01 Individual onboarding arc. The
        // account side (identity) is optional and never a rights gate; the civic
        // side is what actually switches rights on, so it outranks it when both
        // are present — a resident who never linked an ID still reads as
        // associated, not stuck at "registered".
        $associated = $this->roles->associationsFor($user) !== [];
        $verified = $user->status === 'identity_verified' || $user->identity_verified_at !== null;
        $journeyStatus = match (true) {
            $associated       => 'jurisdictionally_associated',
            $claim !== null   => 'residency_declared',
            $verified         => 'identity_verified',
            default           => 'registered',
        };

        return Inertia::render('Civic/IdentityVerification', [
            'surface'  => SurfaceMeta::for('civic/identity-verification'),
            // PHP-owned machine (DESIGN_frontend_port.md §D4) — the full ESM-01
            // Individual onboarding arc, shared with the relocation surface via
            // config. The strip shows the whole journey; journeyStatus marks
            // where the viewer is on it.
            'machine'       => config('cga.state_machines.individual_onboarding'),
            'journeyStatus' => $journeyStatus,
            'identity' => [
                'status'               => $user->status,
                'verified_at'          => $user->identity_verified_at?->toIso8601String(),
                'verified_via'         => $user->identity_verified_via,
                'attestation_requested_at' => $latestRequest?->occurred_at?->toIso8601String(),
            ],
            'declaredJurisdiction' => $claim?->jurisdiction === null ? null : [
                'id'        => $claim->jurisdiction->id,
                'name'      => $claim->jurisdiction->name,
                'adm_level' => $claim->jurisdiction->adm_level,
            ],
        ]);
    }

    /** F-IND-004 — manual attestation request, through the engine. */
    public function requestAttestation(Request $request): RedirectResponse
    {
        $claim = $this->residency->openClaimFor($request->user());

        $this->engine->file('F-IND-004', $request->user(), [
            'jurisdiction_id' => $claim?->jurisdiction_id !== null ? (string) $claim->jurisdiction_id : null,
        ]);

        return back()->with('status', 'Attestation appointment requested — your rights are unaffected while this is pending.');
    }
}
