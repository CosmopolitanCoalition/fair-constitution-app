<?php

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\CgcIpRegisterEntry;
use App\Models\Department;
use App\Models\Law;
use App\Models\Organization;
use App\Models\OrgConversion;
use App\Services\Organizations\CgcIpRegisterService;
use App\Services\RoleService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-D9 — Common Good Corporation detail (PHASE_D_DESIGN_frontend.md §B.8;
 * surface organizations/cgc-detail).
 *
 *   GET  /organizations/{organization}      — OrganizationController routes
 *        is_cgc orgs to THIS component (one route, two page components); a
 *        direct CgcController@show exists for callers that already know the
 *        org is a CGC.
 *   POST /organizations/{organization}/ip-register — one IRREVERSIBLE
 *        public-domain dedication (Art. III §5). Additive only — there is
 *        NO update/delete route, on purpose (the absence is the UI
 *        statement of irreversibility; the engine + DB triggers enforce it
 *        regardless). R-18 of the overseeing board or R-23 of the CGC.
 *
 * Public read (the charter, oversight, board, and the IP register are all
 * public records — Art. II §2, Art. III §5).
 *
 * CONSTITUTIONAL POSTURE — pure renderer: worker_seats / composition_valid
 * are engine snapshots from the boards row; the IP register status column
 * carries one value, public_domain, sourced from CgcIpRegisterEntry rows.
 * Nothing is computed here.
 */
class CgcController extends Controller
{
    public function __construct(
        private readonly CgcIpRegisterService $ipRegister,
        private readonly RoleService $roles,
    ) {}

    public function show(Request $request, Organization $organization): Response
    {
        // OrganizationController@show is the canonical entry for non-CGC
        // orgs; if a private org reaches here, hand it back to that route.
        if (! $organization->is_cgc) {
            return Inertia::location("/organizations/{$organization->id}");
        }

        $organization->loadMissing('jurisdiction:id,name,slug');

        $charterLaw = $organization->created_by_law_id !== null
            ? Law::query()->find($organization->created_by_law_id)
            : null;

        $board = $organization->board_id !== null
            ? Board::query()->with(['seats' => fn ($q) => $q->orderBy('seat_no')])->find($organization->board_id)
            : null;

        $oversight = $this->oversight($organization);

        $roles = $this->roles->rolesFor($request->user());

        return Inertia::render('Organizations/CgcDetail', [
            'surface' => SurfaceMeta::for('organizations/cgc-detail'),
            'organization' => [
                'id' => (string) $organization->id,
                'name' => $organization->name,
                'type' => $organization->type,
                'status' => $organization->status,
                'jurisdiction' => $organization->jurisdiction !== null ? [
                    'name' => $organization->jurisdiction->name,
                    'href' => '/jurisdictions/'.($organization->jurisdiction->slug ?? $organization->jurisdiction->id),
                ] : null,
                'purpose' => $organization->purpose,
                'registered_at' => $organization->registered_at?->toDateString(),
                'worker_count' => (int) ($organization->worker_count ?? 0),
            ],
            'charter' => [
                'purpose' => $organization->purpose,
                'act' => $this->lawChip($charterLaw),
                'effective_at' => $charterLaw?->effective_at?->toDateString()
                    ?? $charterLaw?->enacted_at?->toDateString()
                    ?? $organization->registered_at?->toDateString(),
            ],
            'oversight' => $oversight,
            'codet' => $this->codetProps($organization, $board),
            'board' => $this->boardProps($board),
            'ipRegister' => $this->ipRegisterRows($organization),
            'actionsDeepLinks' => [
                'reorganize' => '/legislature/bills?intro=1&act=cgc_reorg&org='.$organization->id,
            ],
            'conversions' => $this->conversionRows($organization),
            'can' => [
                // R-18 (overseeing department governor) or R-23 (the CGC
                // agent) may register a dedication; the SERVICE is the real
                // wall (Art. III §5 — non-CGC rejected, kind validated).
                'registerIp' => $request->user() !== null
                    && (in_array('R-18', $roles, true) || in_array('R-23', $roles, true)),
            ],
            'urls' => [
                'ipRegister' => "/organizations/{$organization->id}/ip-register",
            ],
        ]);
    }

    /**
     * One irreversible public-domain dedication (Art. III §5). The service
     * is the ONLY writer of cgc_ip_register and throws a
     * ConstitutionalViolation (globally rendered to errors.constitution)
     * for a non-CGC org or an unknown kind — there is NO status field on
     * the form because public_domain is the only representable value.
     */
    public function registerIp(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'asset' => ['required', 'string', 'max:300'],
            'kind' => ['required', 'string'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->ipRegister->dedicate(
            $organization,
            $validated['asset'],
            $validated['kind'],
            $validated['description'] ?? null,
            'F-LEG-019', // the CGC's chartering form anchors ad-hoc dedications
            $request->user()?->getKey() !== null ? (string) $request->user()->getKey() : null,
        );

        return back()->with(
            'status',
            'Dedicated to the public domain, irreversibly (Art. III §5). The entry is appended to the '
            .'register and sealed to the public record — it can never be edited or revoked.'
        );
    }

    // =========================================================================
    // Presentation internals
    // =========================================================================

    /** The overseeing department + executive, resolved through the act. */
    private function oversight(Organization $organization): ?array
    {
        // A CGC names its overseeing executive at chartering; the
        // department that oversees it (if any) links the CGC by oversight.
        $department = Department::query()
            ->where('jurisdiction_id', $organization->jurisdiction_id)
            ->where('executive_id', $organization->overseen_by_executive_id)
            ->with('executive:id,jurisdiction_id')
            ->first();

        if ($organization->overseen_by_executive_id === null) {
            return null;
        }

        return [
            'department' => $department !== null ? [
                'name' => $department->name,
                'href' => "/departments/{$department->id}",
            ] : null,
            'executive' => [
                'name' => 'Executive of '.($organization->jurisdiction?->name ?? 'the jurisdiction'),
                'href' => "/executives/{$organization->overseen_by_executive_id}",
            ],
            'reporting_interval' => $department?->reporting_interval_months,
        ];
    }

    /** CoDetScale props — governors stand where shareholders would (#12). */
    private function codetProps(Organization $organization, ?Board $board): ?array
    {
        if ($board === null) {
            return null;
        }

        $workers = (int) ($board->worker_headcount ?? $organization->worker_count ?? 0);
        $ownerSeats = (int) $board->owner_seats;
        $workerSeats = (int) $board->worker_seats; // engine snapshot — never recomputed

        return [
            'workers' => $workers,
            'ownerSeats' => $ownerSeats,
            'workerSeats' => $workerSeats,
            // CLK-13/14 are AMENDABLE — the live resolved board thresholds
            // are not available on the row, so the page renders the
            // Template defaults the scale was last evaluated against. The
            // co-determination surface holds the authoritative amendable
            // values; this static readout mirrors the engine's own seats.
            'thresholds' => ['min' => 100, 'parity' => 2000],
            'nextStepAt' => \App\Services\Organizations\CoDeterminationService::nextStep($workerSeats, $ownerSeats),
            'entityLabel' => $organization->name,
        ];
    }

    /** BoardStrip props from the unified board + its seats. */
    private function boardProps(?Board $board): ?array
    {
        if ($board === null) {
            return null;
        }

        return [
            'compositionValid' => (bool) $board->composition_valid,
            'requiredWorkerSeats' => (int) $board->worker_seats,
            'seats' => $board->seats->map(fn (BoardSeat $seat) => $this->seatRow($seat))->all(),
        ];
    }

    private function seatRow(BoardSeat $seat): array
    {
        $seat->loadMissing(['holder:id,name,display_name', 'term']);

        return [
            'id' => (string) $seat->id,
            'seat_class' => $seat->seat_class,
            'holder' => $seat->holder !== null
                ? ['name' => $seat->holder->display_name ?? $seat->holder->name]
                : null,
            'is_chair' => (bool) $seat->is_chair,
            'status' => $seat->status,
            'term' => $seat->term !== null ? [
                'starts_on' => $seat->term->starts_on?->toDateString() ?? null,
                'ends_on' => $seat->term->ends_on?->toDateString() ?? null,
                'clock' => $seat->seat_class === BoardSeat::CLASS_WORKER_ELECTED ? 'CLK-10' : 'CLK-09',
            ] : null,
        ];
    }

    /**
     * The public-domain IP register — status is ALWAYS public_domain (the
     * column admits one value; CgcIpRegisterEntry::STATUS_PUBLIC_DOMAIN).
     */
    private function ipRegisterRows(Organization $organization): array
    {
        // READ through the relation, never the register model statically —
        // CgcIpRegisterService::dedicate() is the only writer (Art. III §5,
        // pinned by CgcIpPublicDomainTest).
        return $organization->ipRegisterEntries
            ->map(fn (CgcIpRegisterEntry $entry) => [
                'asset' => $entry->asset,
                'kind' => $entry->kind,
                'published_at' => $entry->published_at?->toDateString(),
                'status' => $entry->status, // public_domain — the only value
            ])
            ->all();
    }

    /** org_conversions history (LifecycleTracker when one exists). */
    private function conversionRows(Organization $organization): array
    {
        return OrgConversion::query()
            ->where('organization_id', $organization->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (OrgConversion $conversion) => [
                'direction' => $conversion->direction,
                'via' => $conversion->via,
                'status' => $conversion->status,
            ])
            ->all();
    }

    private function lawChip(?Law $law): ?array
    {
        if ($law === null) {
            return null;
        }

        return [
            'act_number' => $law->act_number,
            'href' => $law->enacting_bill_id !== null
                ? "/bills/{$law->enacting_bill_id}"
                : '/system/public-records',
        ];
    }
}
