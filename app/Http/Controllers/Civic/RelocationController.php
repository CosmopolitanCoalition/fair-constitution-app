<?php

namespace App\Http\Controllers\Civic;

use App\Http\Controllers\Controller;
use App\Models\LegislatureMember;
use App\Models\ResidencyClaim;
use App\Services\AuditService;
use App\Services\ResidencyService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-C10 — Relocation (PHASE_C_DESIGN_frontend.md §B.14, WF-CIV-03).
 *
 *   GET  /civic/relocation             show
 *   POST /civic/relocation/travelling  travelling (audited engine action — no F-ID)
 *
 * No citizen-facing form: "I'm moving" reuses F-IND-003 on
 * /civic/residency (a new declaration alongside an active claim IS
 * relocation — ResidencyService::declare). AWAY-PATTERN DETECTION
 * (sustained pings outside WITHOUT a re-declaration) is deferred to
 * Phase F mobile geofencing (PHASE_C_DESIGN_votes_laws §F.2) — the
 * detection prop ships honestly null; the page renders the calm empty
 * state. The grace period IS the new jurisdiction's CLK-05 threshold:
 * rights never gap, the seat vacates only at actual re-association.
 */
class RelocationController extends Controller
{
    public function __construct(
        private readonly ResidencyService $residency,
        private readonly AuditService $audit,
    ) {
    }

    public function show(Request $request): Response
    {
        $user = $request->user();

        $claims = ResidencyClaim::query()
            ->where('user_id', (string) $user->getKey())
            ->open()
            ->with('jurisdiction:id,name,slug,adm_level')
            ->orderBy('declared_at')
            ->get();

        $active = $claims->firstWhere('status', ResidencyClaim::STATUS_ACTIVE);
        $moving = $claims->first(fn (ResidencyClaim $claim) => $claim->status !== ResidencyClaim::STATUS_ACTIVE);

        $newClaim = null;
        if ($active !== null && $moving !== null) {
            $newClaim = [
                'jurisdiction' => $moving->jurisdiction?->name,
                'qualifying_days' => $moving->isMonitoring()
                    ? $this->residency->qualifyingDays($moving)
                    : (int) $moving->qualifying_days,
                'threshold_days' => $this->residency->thresholdDays($moving),
                'status'         => $moving->status,
            ];
        }

        return Inertia::render('Civic/Relocation', [
            'surface' => SurfaceMeta::for('civic/relocation'),
            // Away-pattern detection needs continuous ping telemetry —
            // Phase F mobile geofencing (deferral, votes_laws §F.2). NULL
            // renders the honest empty state; the meter grammar is wired
            // for the day detection arrives.
            'detection' => null,
            'homeClaim' => $active !== null ? [
                'jurisdiction' => ['name' => $active->jurisdiction?->name],
                'status'       => $active->status,
                'declared_at'  => $active->declared_at?->toDateString(),
            ] : null,
            'heldOffices' => $this->heldOffices($user->getKey(), $newClaim),
            'newClaim'    => $newClaim,
            'machine'     => HomeController::claimMachine(),
            'urls'        => [
                'travelling' => '/civic/relocation/travelling',
                'residency'  => '/civic/residency',
            ],
        ]);
    }

    /**
     * "I'm travelling" — resets away-pattern detection. An engine ACTION,
     * not a form (no F-ID exists — by design, §B.14); audit-chained like
     * the Phase B approvals. With detection deferred to Phase F the reset
     * is the recorded declaration itself — nothing else changes, which is
     * exactly the travel promise.
     */
    public function travelling(Request $request): RedirectResponse
    {
        $user = $request->user();

        $active = ResidencyClaim::query()
            ->where('user_id', (string) $user->getKey())
            ->where('status', ResidencyClaim::STATUS_ACTIVE)
            ->first();

        $this->audit->append(
            module: 'residency',
            event: 'relocation.travelling_declared',
            payload: [
                'note' => 'Resident marked the away pattern as travel — detection resets; '
                    . 'residency and every association stay exactly as they are.',
            ],
            ref: 'WF-CIV-03',
            actorId: (string) $user->getKey(),
            jurisdictionId: $active?->jurisdiction_id !== null ? (string) $active->jurisdiction_id : null,
        );

        return back()->with(
            'status',
            'Marked as travel — nothing changes. Your residency and every association in your chain '
            . 'stay active; the system only asks again if a new sustained pattern forms (Art. V §1 · CLK-05).'
        );
    }

    // =========================================================================
    // Presentation internals
    // =========================================================================

    /**
     * §B.14 held-office card — rendered only when the viewer holds a seat.
     * Grace runs ONLY while a move is in flight (the new claim's CLK-05
     * threshold IS the constitutional grace).
     */
    private function heldOffices(string $userId, ?array $newClaim): array
    {
        return LegislatureMember::query()
            ->where('user_id', $userId)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->with('legislature.jurisdiction:id,name')
            ->orderBy('seat_no')
            ->get()
            ->map(fn (LegislatureMember $member) => [
                'kind'  => 'legislature_seat',
                'label' => sprintf(
                    'Seat %s · %s legislature',
                    $member->seat_no ?? '—',
                    $member->legislature?->jurisdiction?->name ?? 'Unknown'
                ),
                'grace' => $newClaim !== null ? [
                    'day' => (int) $newClaim['qualifying_days'],
                    'of'  => (int) $newClaim['threshold_days'],
                ] : null,
                'vacates_into' => 'countback',
            ])
            ->values()
            ->all();
    }
}
