<?php

namespace App\Http\Controllers\Civic;

use App\Http\Controllers\Controller;
use App\Models\ResidencyClaim;
use App\Services\ResidencyService;
use App\Services\RoleService;
use App\Support\SurfaceMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WI-8 — GET /civic: the post-login civic dashboard (civic-home contract,
 * EXPLORE_civic_electoral.md §2).
 *
 * Pure dashboard/navigation — no mutations. Renders: rights badges (from
 * shared auth.roles), residency status card (open-claim state or a
 * declare CTA), the association chip chain, elections + petitions lists
 * (HONEST empty states until Phases B/C), my-record stat counts, and a
 * dormant emergency-banner slot (scenario machinery arrives in Phase C).
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly ResidencyService $residency,
        private readonly RoleService $roles,
    ) {
    }

    public function show(Request $request): Response
    {
        $user  = $request->user();
        $claim = $this->residency->openClaimFor($user);

        $claimProps = null;
        if ($claim !== null) {
            $days = $claim->isMonitoring()
                ? $this->residency->qualifyingDays($claim)
                : (int) $claim->qualifying_days;

            $claimProps = [
                'status'          => $claim->status,
                'qualifying_days' => $days,
                'threshold'       => $this->residency->thresholdDays($claim),
                'jurisdiction'    => $claim->jurisdiction === null ? null : [
                    'name'      => $claim->jurisdiction->name,
                    'slug'      => $claim->jurisdiction->slug,
                    'adm_level' => $claim->jurisdiction->adm_level,
                ],
            ];
        }

        $associations = $this->roles->associationsFor($user);

        // Elections in the viewer's FOOTPRINT: one row per non-cancelled
        // election whose jurisdiction the viewer is actively associated
        // with (the Art. I association sweep covers every enclosing level,
        // so a San Marino resident sees the San Marino chamber's election).
        // Live phases rank ahead of certified/final.
        $associationIds = array_column($associations, 'id');

        $elections = $associationIds === [] ? [] : DB::table('elections as e')
            ->join('jurisdictions as j', 'j.id', '=', 'e.jurisdiction_id')
            ->whereNull('e.deleted_at')
            ->where('e.status', '!=', 'cancelled')
            ->whereIn('e.jurisdiction_id', $associationIds)
            ->orderByRaw("CASE WHEN e.status IN ('certified', 'final') THEN 1 ELSE 0 END")
            ->orderByDesc('e.created_at')
            ->limit(10)
            ->get(['e.id', 'e.status', 'e.kind', 'j.name as jurisdiction_name', 'j.adm_level'])
            ->map(fn ($row) => [
                'id'           => (string) $row->id,
                'status'       => $row->status,
                'kind'         => $row->kind,
                'jurisdiction' => $row->jurisdiction_name,
                'adm_level'    => (int) $row->adm_level,
            ])
            ->all();

        // Open petitions in the viewer's association chain (FE-C10 — the
        // Phase A honest empty state retired with the Phase C flip).
        $petitions = $associationIds === [] ? [] : \App\Models\Petition::query()
            ->whereIn('jurisdiction_id', $associationIds)
            ->whereNotIn('status', [
                \App\Models\Petition::STATUS_ADOPTED,
                \App\Models\Petition::STATUS_REJECTED,
                \App\Models\Petition::STATUS_INVALIDATED,
            ])
            ->with('jurisdiction:id,name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($petition) => [
                'id'              => (string) $petition->id,
                'title'           => $petition->title,
                'jurisdiction'    => $petition->jurisdiction?->name,
                'state'           => $petition->status,
                'signatures'      => $petition->liveSignatureCount(),
                'threshold_count' => (int) $petition->threshold_count,
                'href'            => "/civic/petitions/{$petition->id}",
            ])
            ->all();

        return Inertia::render('Civic/Home', [
            'surface'      => SurfaceMeta::for('civic/home'),
            'claim'        => $claimProps,
            'machine'      => self::claimMachine(),
            'associations' => $associations,
            'stats'        => [
                'record_entries' => DB::table('audit_log')->where('actor_user_id', (string) $user->id)->count(),
                'associations'   => count($associations),
                'ballots_cast'   => 0, // Phase B
                'petitions'      => count($petitions),
            ],
            'elections' => $elections,
            'petitions' => $petitions,
            // Page-level emergency slot stays null — the Art. II §7 banner
            // ships SHELL-WIDE via the app.activeEmergencies shared prop
            // (HandleInertiaRequests, FE-C9); rendering it here too would
            // double the alert.
            'emergency' => null,
        ]);
    }

    /**
     * Residency-Claim machine, PHP-owned (DESIGN_frontend_port.md §D4) —
     * the UI never hardcodes state lists.
     *
     * @return list<string>
     */
    public static function claimMachine(): array
    {
        return [
            ResidencyClaim::STATUS_DECLARED,
            ResidencyClaim::STATUS_PING_MONITORING,
            ResidencyClaim::STATUS_THRESHOLD_MET,
            ResidencyClaim::STATUS_VERIFIED,
            ResidencyClaim::STATUS_ACTIVE,
        ];
    }
}
