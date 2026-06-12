<?php

namespace App\Http\Controllers\Civic;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\ResidencyClaim;
use App\Services\ResidencyService;
use App\Services\RoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WI-5 — Civic/Residency: the resident-facing claim lifecycle.
 *
 *   GET  /civic/residency            — claim state, ping meter, panel state
 *   POST /civic/residency/declare    — F-IND-003 via the engine
 *   POST /civic/residency/confirm    — "this is my residence" → F-IND-006
 *   POST /civic/residency/redeclare  — correct the boundary → new F-IND-003
 *
 * The Inertia page (Civic/Residency) is a WI-8 deliverable; until then a
 * placeholder renders the props verbatim.
 */
class ResidencyController extends Controller
{
    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly ResidencyService $residency,
        private readonly RoleService $roles,
    ) {
    }

    public function show(Request $request): Response
    {
        $user  = $request->user();
        $claim = $this->residency->openClaimFor($user);

        $claimProps = null;
        $threshold  = null;
        $panel      = 'undeclared';

        if ($claim !== null) {
            $threshold = $this->residency->thresholdDays($claim);

            // Live recount while monitoring (pings purge at verification, so
            // an active claim reports its stored count).
            $days = $claim->isMonitoring()
                ? $this->residency->qualifyingDays($claim)
                : (int) $claim->qualifying_days;

            $panel = match (true) {
                $claim->status === ResidencyClaim::STATUS_ACTIVE => 'verified',
                $days >= $threshold                              => 'pending_confirmation',
                default                                          => 'locked',
            };

            $jurisdiction = $claim->jurisdiction;

            $claimProps = [
                'id'               => $claim->id,
                'status'           => $claim->status,
                'declared_at'      => $claim->declared_at?->toIso8601String(),
                'qualifying_days'  => $days,
                'threshold_met_at' => $claim->threshold_met_at?->toIso8601String(),
                'verified_at'      => $claim->verified_at?->toIso8601String(),
                'jurisdiction'     => $jurisdiction === null ? null : [
                    'id'        => $jurisdiction->id,
                    'name'      => $jurisdiction->name,
                    'slug'      => $jurisdiction->slug,
                    'adm_level' => $jurisdiction->adm_level,
                ],
            ];
        }

        return Inertia::render('Civic/Residency', [
            'claim'        => $claimProps,
            'threshold'    => $threshold,
            'panel'        => $panel,
            'associations' => $this->roles->associationsFor($user),
        ]);
    }

    /** F-IND-003 — declare residency at the smallest containing boundary. */
    public function declare(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'jurisdiction_id' => ['required', 'uuid'],
            'ping_consent'    => ['required', 'accepted'],
        ]);

        $this->engine->file('F-IND-003', $request->user(), [
            'jurisdiction_id' => $validated['jurisdiction_id'],
            'ping_consent'    => true,
        ]);

        return back()->with('status', 'Residency declared — ping monitoring started.');
    }

    /**
     * "This is my residence" — the resident's confirmation once the
     * qualifying-day threshold is met. System-files F-IND-006 (the sweep
     * recomputes and re-guards the threshold inside the transaction).
     */
    public function confirm(Request $request): RedirectResponse
    {
        $claim = $this->residency->openClaimFor($request->user());

        if ($claim === null || ! $claim->isMonitoring()) {
            throw ValidationException::withMessages([
                'claim' => 'No residency claim is awaiting confirmation.',
            ]);
        }

        $this->residency->verify($claim);

        return back()->with('status', 'Residency verified — your jurisdictional associations are active.');
    }

    /** Correct the boundary: a new F-IND-003 superseding the open claim. */
    public function redeclare(Request $request): RedirectResponse
    {
        return $this->declare($request);
    }
}
