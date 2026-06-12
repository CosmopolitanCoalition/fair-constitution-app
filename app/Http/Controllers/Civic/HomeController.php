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

        return Inertia::render('Civic/Home', [
            'surface'      => SurfaceMeta::for('civic/home'),
            'claim'        => $claimProps,
            'machine'      => self::claimMachine(),
            'associations' => $associations,
            'stats'        => [
                'record_entries' => DB::table('audit_log')->where('actor_user_id', (string) $user->id)->count(),
                'associations'   => count($associations),
                'ballots_cast'   => 0, // Phase B
                'petitions'      => 0, // Phase C
            ],
            // HONEST empty states — these institutions ship in B/C; the
            // dashboard never fakes them with fixtures.
            'elections' => [],
            'petitions' => [],
            // Emergency banner slot: dormant until Phase C (Art. II §7
            // scenario machinery). Null = nothing renders.
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
