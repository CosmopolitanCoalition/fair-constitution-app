<?php

namespace App\Http\Controllers\Civic;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\ResidencyClaim;
use App\Services\ResidencyService;
use App\Services\RoleService;
use App\Support\SurfaceMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WI-5/WI-8 — Civic/Residency: the resident-facing claim lifecycle.
 *
 *   GET  /civic/residency             — claim state, ping meter, panel state
 *   GET  /civic/jurisdictions/search  — declare-form jurisdiction picker
 *   POST /civic/residency/declare     — F-IND-003 via the engine
 *   POST /civic/residency/confirm     — "this is my residence" → F-IND-006
 *   POST /civic/residency/redeclare   — correct the boundary → new F-IND-003
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
            'surface'      => SurfaceMeta::for('civic/residency'),
            'claim'        => $claimProps,
            // PHP-owned machine definition (DESIGN_frontend_port.md §D4).
            'machine'      => HomeController::claimMachine(),
            'threshold'    => $threshold,
            // Code fallback shown before any claim exists (per-jurisdiction
            // constitutional_settings resolve once a boundary is declared).
            'defaultThreshold' => ResidencyService::DEFAULT_THRESHOLD_DAYS,
            'panel'        => $panel,
            'associations' => $this->roles->associationsFor($user),
        ]);
    }

    /**
     * GET /civic/jurisdictions/search?q= — declare-form picker. Name/slug
     * ILIKE, boundary required (pings must be containable), prefix matches
     * first, then deepest (smallest) boundaries — the contract says declare
     * the SMALLEST boundary you live inside.
     */
    public function searchJurisdictions(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $like    = "%{$escaped}%";
        $prefix  = "{$escaped}%";

        $rows = DB::table('jurisdictions as j')
            ->leftJoin('jurisdictions as p', 'p.id', '=', 'j.parent_id')
            ->whereNull('j.deleted_at')
            ->whereNotNull('j.geom')
            ->where(function ($query) use ($like) {
                $query->where('j.name', 'ilike', $like)
                    ->orWhere('j.slug', 'ilike', $like);
            })
            ->orderByRaw('(j.name ILIKE ?) DESC', [$prefix])
            ->orderByDesc('j.adm_level')
            ->orderBy('j.name')
            ->limit(20)
            ->get(['j.id', 'j.name', 'j.slug', 'j.adm_level', 'p.name as parent_name']);

        return response()->json([
            'results' => $rows->map(fn ($row) => [
                'id'          => (string) $row->id,
                'name'        => $row->name,
                'slug'        => $row->slug,
                'adm_level'   => (int) $row->adm_level,
                'parent_name' => $row->parent_name,
            ]),
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
