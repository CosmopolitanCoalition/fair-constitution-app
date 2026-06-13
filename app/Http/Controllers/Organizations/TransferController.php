<?php

namespace App\Http\Controllers\Organizations;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Organization;
use App\Models\OrgConversion;
use App\Models\OrgTransfer;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-D9 — Transfers and conversions (PHASE_D_DESIGN_frontend.md §B.11;
 * surface organizations/transfers-conversions).
 *
 *   GET  /organizations/transfers-conversions[?org={id}]  index (public read)
 *   POST /organizations/{organization}/transfers          F-ORG-005 initiate
 *   POST /transfers/{transfer}/consent                    F-ORG-005 consent
 *   POST /organizations/{organization}/conversion-requests F-ORG-006
 *   POST /organizations/{organization}/dissolution        F-ORG-007
 *
 * Four constitutional ownership paths (the mockup's structure):
 *  1. Mutual transfer — F-ORG-005, BOTH consents on record (the engine
 *     rejects anything less).
 *  2. Monopoly acquisition — F-LEG-026, a legislative act at ORDINARY
 *     majority of all serving (the ONLY path overriding owner consent),
 *     compensation ≥ the recorded fair-market floor (hardened, Art. III
 *     §5). Initiated through the bill flow — this page renders the running
 *     conversion + its vote, never originates it.
 *  3. Public↔private conversion — F-ORG-006 request → F-LEG-027 act.
 *  4. Internal restructuring (owner consent per the structure's own rules)
 *     + voluntary dissolution (F-ORG-007); judicial dissolution is Phase E.
 *
 * CONSTITUTIONAL POSTURE — pure renderer: the acquisition vote's threshold
 * numbers are engine snapshots from the chamber_votes row; the fair-market
 * floor and compensation are recorded facts on org_conversions. POSTs all
 * run through ConstitutionalEngine::file(); a ConstitutionalViolation is
 * globally rendered to errors.constitution with its citation.
 */
class TransferController extends Controller
{
    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly ChamberVotePresenter $votes,
    ) {}

    public function index(Request $request): Response
    {
        $focusOrgId = $request->query('org');

        $focusOrg = $focusOrgId !== null
            ? Organization::query()->find($focusOrgId)
            : null;

        // Scope every register to the focused org when ?org= is present,
        // else show the whole (typically empty on day one) registry.
        $orgFilter = fn ($query) => $focusOrg !== null
            ? $query->where('organization_id', $focusOrg->id)
            : $query;

        return Inertia::render('Organizations/TransfersConversions', [
            'surface' => SurfaceMeta::for('organizations/transfers-conversions'),
            'focus' => $focusOrg !== null ? [
                'id' => (string) $focusOrg->id,
                'name' => $focusOrg->name,
                'href' => "/organizations/{$focusOrg->id}",
            ] : null,
            'transfers' => $this->transferRows($orgFilter),
            'acquisitions' => $this->acquisitionRows($orgFilter),
            'conversions' => $this->conversionRows($orgFilter),
            'restructurings' => [], // structure-history events live on OrgDetail/OwnershipPanel; none modelled here
            'dissolutions' => $this->dissolutionRows($focusOrg),
            'deepLinks' => [
                // F-LEG-026/F-LEG-027 are legislative acts — they ride the
                // bill flow; this page deep-links, it never POSTs them.
                'monopolyAcquisition' => '/legislature/bills?intro=1&act=monopoly_acquisition'
                    .($focusOrg !== null ? '&org='.$focusOrg->id : ''),
                'cgcReorgSale' => '/legislature/bills?intro=1&act=cgc_reorg'
                    .($focusOrg !== null ? '&org='.$focusOrg->id : ''),
            ],
            'can' => [
                // Initiation gates R-23/R-24 at the engine; the page
                // explains rather than 403s. The forms target a specific
                // org, so the real gate is the agent/membership check the
                // engine runs on file().
                'initiateTransfer' => $request->user() !== null,
                'requestConversion' => $request->user() !== null,
                'dissolve' => $request->user() !== null,
            ],
            'urls' => $focusOrg !== null ? [
                'transfer' => "/organizations/{$focusOrg->id}/transfers",
                'conversionRequest' => "/organizations/{$focusOrg->id}/conversion-requests",
                'dissolution' => "/organizations/{$focusOrg->id}/dissolution",
            ] : null,
        ]);
    }

    // =========================================================================
    // POSTs — all through the engine (citations rendered globally on 422)
    // =========================================================================

    /** F-ORG-005 'initiate' — from-side consent recorded at filing. */
    public function transfer(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'to_party_type' => ['required', 'string', 'in:users,organizations'],
            'to_party_id' => ['required', 'string'],
            'terms' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->engine->file('F-ORG-005', $request->user(), [
            'action' => 'initiate',
            'organization_id' => (string) $organization->id,
            'jurisdiction_id' => (string) $organization->jurisdiction_id,
            'to_party_type' => $validated['to_party_type'],
            'to_party_id' => $validated['to_party_id'],
            'terms' => $validated['terms'] ?? null,
        ]);

        return back()->with(
            'status',
            'Transfer initiated — your side has consented (F-ORG-005). The transferee must consent before '
            .'anything moves; the engine rejects completion with anything less than both consents.'
        );
    }

    /** F-ORG-005 'consent' — the named transferee co-consents. */
    public function consent(Request $request, OrgTransfer $transfer): RedirectResponse
    {
        $this->engine->file('F-ORG-005', $request->user(), [
            'action' => 'consent',
            'transfer_id' => (string) $transfer->id,
            'jurisdiction_id' => (string) $transfer->organization?->jurisdiction_id,
        ]);

        return back()->with(
            'status',
            'Consent recorded — both consents are now on record (F-ORG-005). Ownership transfers by mutual '
            .'consent, never by a hostile path.'
        );
    }

    /** F-ORG-006 — a conversion REQUEST routed to the legislature. */
    public function conversionRequest(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'direction' => ['required', 'string', 'in:private_to_cgc,cgc_to_private'],
            'rationale' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->engine->file('F-ORG-006', $request->user(), [
            'organization_id' => (string) $organization->id,
            'jurisdiction_id' => (string) $organization->jurisdiction_id,
            'direction' => $validated['direction'],
            'rationale' => $validated['rationale'] ?? null,
        ]);

        return back()->with(
            'status',
            'Conversion request filed (F-ORG-006) — a request, not an act. Both directions are '
            .'legislature-only; the legislature decides by F-LEG-026 / F-LEG-027 (Art. III §5).'
        );
    }

    /** F-ORG-007 — voluntary dissolution (WF-ORG-10 voluntary path). */
    public function dissolution(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->engine->file('F-ORG-007', $request->user(), [
            'organization_id' => (string) $organization->id,
            'jurisdiction_id' => (string) $organization->jurisdiction_id,
            'reason' => $validated['reason'] ?? null,
        ]);

        return back()->with(
            'status',
            'Dissolution filed (F-ORG-007) — obligations settled, records archived, the audit chain preserved. '
            .'A CGC dissolves only by legislative act (F-LEG-027).'
        );
    }

    // =========================================================================
    // Presentation internals
    // =========================================================================

    private function transferRows(callable $scope): array
    {
        return $scope(OrgTransfer::query()->with('organization:id,name'))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (OrgTransfer $transfer) => [
                'id' => (string) $transfer->id,
                'from' => $transfer->organization !== null ? [
                    'name' => $transfer->organization->name,
                    'href' => "/organizations/{$transfer->organization->id}",
                ] : null,
                'to' => [
                    'type' => rtrim($transfer->to_party_type, 's'),
                    'name' => (string) $transfer->to_party_id,
                ],
                'status' => $transfer->status,
                'consent_a_at' => $transfer->consent_from_at?->toDateString(),
                'consent_b_at' => $transfer->consent_to_at?->toDateString(),
                // Phase F federation sync — null on a single instance.
                'ffc_synced_at' => $transfer->ffc_synced_at?->toDateString(),
                'consent_url' => "/transfers/{$transfer->id}/consent",
            ])
            ->all();
    }

    /**
     * Monopoly acquisitions (private_to_cgc via monopoly_acquisition). The
     * vote is the ordinary-majority chamber vote on the F-LEG-026 proposal;
     * its numbers are engine snapshots.
     */
    private function acquisitionRows(callable $scope): array
    {
        $rows = $scope(
            OrgConversion::query()
                ->with('organization:id,name')
                ->where('via', OrgConversion::VIA_MONOPOLY_ACQUISITION)
        )
            ->orderBy('created_at')
            ->get();

        // The 5 stages: legislative finding → acquisition vote → compensation
        // ≥ floor → conversion to CGC → governor offers. stage_index is read
        // off the conversion status — never computed as policy here.
        $stageForStatus = [
            OrgConversion::STATUS_PROPOSED => 1,
            OrgConversion::STATUS_VOTED => 1,
            OrgConversion::STATUS_COMPENSATION_PENDING => 2,
            OrgConversion::STATUS_CONVERTING => 3,
            OrgConversion::STATUS_COMPLETED => 4,
            OrgConversion::STATUS_ABANDONED => 1,
        ];

        return $rows->map(function (OrgConversion $conversion) use ($stageForStatus) {
            $proposal = $conversion->proposal_id !== null
                ? ChamberVoteProposal::query()->find($conversion->proposal_id)
                : null;

            $vote = $proposal?->vote_id !== null
                ? ChamberVote::query()->with('tallies')->find($proposal->vote_id)
                : null;

            $offers = is_array($conversion->board_transition) ? $conversion->board_transition : [];

            return [
                'org' => $conversion->organization !== null ? [
                    'name' => $conversion->organization->name,
                    'href' => "/organizations/{$conversion->organization->id}",
                ] : null,
                'stage_index' => $stageForStatus[$conversion->status] ?? 0,
                'status' => $conversion->status,
                'finding' => $conversion->authorizing_law_id !== null ? [
                    'href' => '/system/public-records',
                ] : null,
                'vote' => $vote !== null ? ['tally' => $this->votes->tallyProps($vote)] : null,
                'compensation' => [
                    'amount' => $conversion->compensation !== null ? (string) $conversion->compensation : null,
                    'fair_market_floor' => $conversion->fair_market_floor !== null ? (string) $conversion->fair_market_floor : null,
                ],
                'governor_offers' => array_map(fn (array $offer) => [
                    'user_id' => (string) ($offer['user_id'] ?? ''),
                    'status' => (string) ($offer['response'] ?? 'pending'),
                ], $offers),
            ];
        })->all();
    }

    /**
     * Public↔private conversion requests + sales (everything NOT a monopoly
     * acquisition — mutual private_to_cgc requests and cgc_to_private sales).
     */
    private function conversionRows(callable $scope): array
    {
        return $scope(
            OrgConversion::query()
                ->with('organization:id,name')
                ->where('via', '!=', OrgConversion::VIA_MONOPOLY_ACQUISITION)
        )
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (OrgConversion $conversion) => [
                'org' => $conversion->organization !== null ? [
                    'name' => $conversion->organization->name,
                    'href' => "/organizations/{$conversion->organization->id}",
                ] : null,
                'direction' => $conversion->direction,
                'via' => $conversion->via,
                'authorizing_act' => $conversion->authorizing_law_id !== null ? [
                    'href' => '/system/public-records',
                ] : null,
                'status' => $conversion->status,
            ])
            ->all();
    }

    /**
     * Voluntary dissolutions (dissolved orgs). The judicial path
     * (WF-ORG-10) is Phase E — rendered as a planned-flag in the page.
     */
    private function dissolutionRows(?Organization $focusOrg): array
    {
        return Organization::query()
            ->where('status', Organization::STATUS_DISSOLVED)
            ->when($focusOrg !== null, fn ($q) => $q->whereKey($focusOrg->id))
            ->orderByDesc('dissolved_at')
            ->get()
            ->map(fn (Organization $org) => [
                'org' => [
                    'name' => $org->name,
                    'href' => "/organizations/{$org->id}",
                ],
                'kind' => 'voluntary',
                'status' => $org->status,
                'archived_record_href' => $org->registration_record_id !== null
                    ? '/system/public-records'
                    : null,
            ])
            ->all();
    }
}
