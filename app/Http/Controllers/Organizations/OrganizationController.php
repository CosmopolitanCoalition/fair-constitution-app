<?php

namespace App\Http\Controllers\Organizations;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\EndorsementRequest;
use App\Models\Organization;
use App\Models\OrgContract;
use App\Models\OrgDocumentPackage;
use App\Models\OrgMembership;
use App\Models\OrgOwnershipStake;
use App\Models\OrgWorker;
use App\Services\Organizations\CoDeterminationService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-D6 — Organizations Registry + OrgDetail (PHASE_D_DESIGN_frontend.md
 * §B.6 + §B.7; surfaces organizations/org-registry + organizations/org-detail).
 *
 *   GET  /organizations              — the registry (stat tiles, FilterBar,
 *        the co-determination cell) + the F-IND-012 registration FormCard.
 *   POST /organizations              — F-IND-012 registration (R-03).
 *   GET  /organizations/{organization} — the profile; 302 to the CGC
 *        component when is_cgc (one route, two page components).
 *   PATCH /organizations/{o}              — F-ORG-001 'update_profile' (R-23).
 *   POST  /organizations/{o}/memberships  — F-IND-013 (any R-01).
 *   POST  /organizations/{o}/workers       — F-IND-014 — the headcount feed (R-01).
 *   POST  /organizations/{o}/documents     — F-ORG-001 'manage_document_package'.
 *   POST  /organizations/{o}/contracts     — F-ORG-001 draft (commercial/other).
 *   POST  /contracts/{contract}/cosign     — F-ORG-001 'countersign_contract'.
 *   POST  /organizations/{o}/endorsements/{request}/grant — F-ORG-002 (R-23).
 *
 * Public read (the registry is visibility 'all', a profile is a public
 * record — Art. II §2 · Art. III); actions gate by derived role through
 * `can.*` + engine 422 (the bootstrap ConstitutionalViolation handler
 * renders the citation back to the page — never a 403).
 *
 * Every threshold / seat count is an ENGINE SNAPSHOT read from a row
 * (boards.worker_seats / .composition_valid, CoDeterminationService::
 * nextStep projection) — nothing is computed in this controller.
 */
class OrganizationController extends Controller
{
    /** ESM-18 ownership-structure rule glosses (the registration select hints). */
    private const STRUCTURE_GLOSS = [
        Organization::STRUCTURE_STOCK => 'Shares decide the owner side; members are shareholders (R-24).',
        Organization::STRUCTURE_PARTNERSHIP => 'Partners are the owners; changes follow the partnership agreement.',
        Organization::STRUCTURE_EQUAL_PARTNERSHIP => 'Equal partners; partnership changes require unanimity.',
        Organization::STRUCTURE_MEMBER_OWNED => 'Member-owned; the membership governs per its adopted rules.',
        Organization::STRUCTURE_WORKER_OWNED => 'Worker-owned; the worker-members are the owner side.',
        Organization::STRUCTURE_NONPROFIT => 'Nonprofit; no ownership stakes — the board governs per its charter.',
    ];

    private const TYPES = [
        Organization::TYPE_POLITICAL_PARTY,
        Organization::TYPE_BUSINESS,
        Organization::TYPE_NONPROFIT,
        Organization::TYPE_INFORMAL,
    ];

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly RoleService $roles,
        private readonly SettingsResolver $settings,
    ) {}

    // =========================================================================
    // GET /organizations — the registry (§B.6)
    // =========================================================================

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $roles = $this->roles->rolesFor($viewer);

        $associations = $viewer !== null ? $this->roles->associationsFor($viewer) : [];

        $orgs = Organization::query()
            ->whereNull('deleted_at')
            ->whereNot('status', Organization::STATUS_DISSOLVED)
            ->with('jurisdiction:id,name,adm_level')
            ->orderBy('name')
            ->get();

        // ESM-18 thresholds resolve per the viewer's nearest association
        // (the co-determination cell legend) — CLK-13/14 are amendable, so
        // the constants are NEVER hardcoded client-side.
        [$min, $parity] = $this->resolveThresholds($associations);

        $rows = $orgs->map(fn (Organization $org) => $this->registryRow($org, $min, $parity))->all();

        return Inertia::render('Organizations/Registry', [
            'surface' => SurfaceMeta::for('organizations/org-registry'),
            'stats' => $this->registryStats($orgs, $min, $parity),
            'organizations' => $rows,
            'filters' => [
                'types' => self::TYPES,
                'structures' => Organization::STRUCTURES,
                'jurisdictions' => array_map(
                    fn (array $a) => ['id' => $a['id'], 'name' => $a['name'], 'adm_level' => $a['adm_level']],
                    $associations,
                ),
            ],
            'machine' => config('cga.state_machines.organization', []),
            'createForm' => [
                'types' => self::TYPES,
                'structures' => array_map(
                    fn (string $s) => ['value' => $s, 'label' => str_replace('_', ' ', $s), 'rule_gloss' => self::STRUCTURE_GLOSS[$s] ?? null],
                    Organization::STRUCTURES,
                ),
                'jurisdictionOptions' => array_map(
                    fn (array $a) => ['id' => $a['id'], 'name' => $a['name'], 'adm_level' => $a['adm_level']],
                    $associations,
                ),
            ],
            'isAssociated' => in_array('R-03', $roles, true),
            'thresholds' => ['min' => $min, 'parity' => $parity],
        ]);
    }

    /** POST /organizations — F-IND-012 (R-03). */
    public function store(Request $request): RedirectResponse
    {
        $this->engine->file('F-IND-012', $request->user(), [
            'type' => (string) $request->input('type', ''),
            'structure' => $request->input('structure') ?: null,
            'name' => (string) $request->input('name', ''),
            'jurisdiction_id' => (string) $request->input('jurisdiction_id', ''),
            'purpose' => $request->input('purpose') ?: null,
        ]);

        return back()->with('status', 'Organization registered (F-IND-012 · Art. I) — association is the only requirement; the public record carries the entry.');
    }

    // =========================================================================
    // GET /organizations/{organization} — the profile (§B.7)
    // =========================================================================

    public function show(Request $request, Organization $organization): Response|RedirectResponse
    {
        // One route, two components — a CGC routes to the CGC profile
        // (FE-D9 / CgcDetail). The 302 keeps the URL canonical.
        if ($organization->is_cgc) {
            return redirect('/organizations/'.$organization->id.'/cgc');
        }

        $organization->loadMissing('jurisdiction:id,name,adm_level', 'agent:id,name,display_name');

        $viewer = $request->user();
        $viewerId = $viewer !== null ? (string) $viewer->getKey() : null;
        $isAgent = $viewerId !== null && (string) $organization->agent_user_id === $viewerId;

        $memberCounts = $this->memberCounts($organization);
        $workerCount = (int) $organization->worker_count;

        $board = $organization->board_id !== null
            ? Board::query()->with('seats.holder:id,name,display_name', 'seats.term:id,starts_on,ends_on')->find($organization->board_id)
            : null;

        [$min, $parity] = $this->resolveThresholds(
            $organization->jurisdiction_id !== null
                ? [['id' => (string) $organization->jurisdiction_id, 'name' => '', 'adm_level' => 0]]
                : [],
        );

        return Inertia::render('Organizations/OrgDetail', [
            'surface' => SurfaceMeta::for('organizations/org-detail'),
            'organization' => [
                'id' => (string) $organization->id,
                'name' => $organization->name,
                'type' => $organization->type,
                'structure' => $organization->structure,
                'status' => $organization->status,
                'jurisdiction' => $organization->jurisdiction !== null ? [
                    'id' => (string) $organization->jurisdiction->id,
                    'name' => $organization->jurisdiction->name,
                    'adm_level' => (int) $organization->jurisdiction->adm_level,
                ] : null,
                'purpose' => $organization->purpose,
                'registered_at' => $organization->registered_at?->toIso8601String(),
                'agent' => [
                    'name' => $organization->agent?->display_name ?? $organization->agent?->name,
                    'is_viewer' => $isAgent,
                ],
                'worker_count' => $workerCount,
                'member_counts' => $memberCounts,
            ],
            'machine' => config('cga.state_machines.organization', []),
            'ownership' => $this->ownershipProps($organization, $memberCounts, $workerCount),
            'board' => $this->boardProps($organization, $board, $workerCount, $min, $parity),
            'endorsements' => $this->endorsementProps($organization),
            'documents' => $this->documentRows($organization),
            'contracts' => $this->contractRows($organization),
            'myMembership' => $viewerId !== null ? $this->myMembership($organization, $viewerId) : null,
            'myWorker' => $viewerId !== null ? $this->myWorker($organization, $viewerId) : null,
            'can' => [
                'manage' => $isAgent,
                'join' => in_array('R-01', $this->roles->rolesFor($viewer), true) && $organization->status === Organization::STATUS_ACTIVE,
                'registerWorker' => in_array('R-01', $this->roles->rolesFor($viewer), true) && $organization->status === Organization::STATUS_ACTIVE,
                'cosign' => $isAgent,
            ],
        ]);
    }

    // =========================================================================
    // POST endpoints — all through the engine (§B.7)
    // =========================================================================

    /** PATCH /organizations/{o} — F-ORG-001 'update_profile' (R-23). */
    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $this->engine->file('F-ORG-001', $request->user(), [
            'action' => 'update_profile',
            'organization_id' => (string) $organization->id,
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'website_url' => $request->input('website_url'),
            'purpose' => $request->input('purpose'),
        ]);

        return back()->with('status', 'Profile updated (F-ORG-001 · R-23).');
    }

    /** POST /organizations/{o}/memberships — F-IND-013 (R-01). */
    public function storeMembership(Request $request, Organization $organization): RedirectResponse
    {
        $this->engine->file('F-IND-013', $request->user(), [
            'organization_id' => (string) $organization->id,
            'kind' => $request->input('kind') ?: null,
        ]);

        return back()->with('status', 'Membership application filed (F-IND-013 · WF-ORG-03) — R-24 derives on the organization\'s acceptance.');
    }

    /** POST /organizations/{o}/workers — F-IND-014, the headcount feed (R-01). */
    public function storeWorker(Request $request, Organization $organization): RedirectResponse
    {
        $this->engine->file('F-IND-014', $request->user(), [
            'employer_type' => OrgWorker::EMPLOYER_ORGANIZATIONS,
            'employer_id' => (string) $organization->id,
            'contract_terms' => $request->input('contract_terms') ?: null,
        ]);

        return back()->with('status', 'Worker registration filed (F-IND-014 · Art. III §6) — activates on the organization\'s countersign; headcount feeds the co-determination scale (CLK-13 / CLK-14).');
    }

    /** POST /organizations/{o}/documents — F-ORG-001 'manage_document_package' (R-23). */
    public function storeDocument(Request $request, Organization $organization): RedirectResponse
    {
        $this->engine->file('F-ORG-001', $request->user(), [
            'action' => 'manage_document_package',
            'organization_id' => (string) $organization->id,
            'key' => (string) $request->input('key', ''),
            'name' => $request->input('name'),
            'kind' => $request->input('kind') ?: 'other',
            'content' => (string) $request->input('content', ''),
        ]);

        return back()->with('status', 'Document package version recorded (F-ORG-001) — internal packages never override the constitutional forms.');
    }

    /** POST /contracts/{contract}/cosign — F-ORG-001 'countersign_contract' (R-23). */
    public function cosignContract(Request $request, OrgContract $contract): RedirectResponse
    {
        $this->engine->file('F-ORG-001', $request->user(), [
            'action' => 'countersign_contract',
            'organization_id' => (string) $contract->organization_id,
            'contract_id' => (string) $contract->id,
        ]);

        return back()->with('status', 'Contract countersigned (F-ORG-001) — both signatures on record; the contract takes effect only with both.');
    }

    /** POST /organizations/{o}/endorsements/{request}/grant — F-ORG-002 (R-23). */
    public function grantEndorsement(Request $request, Organization $organization, EndorsementRequest $endorsementRequest): RedirectResponse
    {
        $this->engine->file('F-ORG-002', $request->user(), [
            'request_id' => (string) $endorsementRequest->id,
            'decision' => (string) $request->input('decision', 'grant'),
            'statement' => $request->input('statement') ?: null,
        ]);

        return back()->with('status', 'Endorsement decided (F-ORG-002) — a grant is forced public and confers R-07 on the candidate.');
    }

    // -------------------------------------------------------------------------
    // Registry helpers (§B.6)
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function registryRow(Organization $org, int $min, int $parity): array
    {
        $endorsementCount = DB::table('endorsements')
            ->where('endorser_type', 'organization')
            ->where('endorser_id', (string) $org->id)
            ->where('is_active', true)
            ->count();

        return [
            'id' => (string) $org->id,
            'name' => $org->name,
            'type' => $org->type,
            'structure' => $org->structure,
            'jurisdiction' => $org->jurisdiction !== null
                ? ['name' => $org->jurisdiction->name, 'adm_level' => (int) $org->jurisdiction->adm_level]
                : null,
            'workers' => (int) $org->worker_count,
            'endorsement_count' => $endorsementCount,
            // The co-det cell reads the ENGINE seat snapshot from the board
            // row; absent a board there is no scaling state to show.
            'codet' => $this->codetCell($org, $min, $parity),
            'is_cgc' => (bool) $org->is_cgc,
            'status' => $org->status,
            'href' => '/organizations/'.$org->id,
        ];
    }

    /**
     * The co-determination cell: state from the live headcount against the
     * resolved thresholds; worker_seats is the engine's board snapshot
     * (never recomputed here). Null when no board exists yet.
     *
     * @return array{state: string, worker_seats: int}|null
     */
    private function codetCell(Organization $org, int $min, int $parity): ?array
    {
        if ($org->board_id === null) {
            return null;
        }

        $workerSeats = (int) (Board::query()->whereKey($org->board_id)->value('worker_seats') ?? 0);
        $workers = (int) $org->worker_count;

        $state = $workers >= $parity ? 'parity' : ($workers >= $min ? 'scaling' : 'below');

        return ['state' => $state, 'worker_seats' => $workerSeats];
    }

    /** @return array{total: int, endorsing: int, in_codetermination: int, cgcs: int} */
    private function registryStats($orgs, int $min, int $parity): array
    {
        $endorserIds = DB::table('endorsements')
            ->where('endorser_type', 'organization')
            ->where('is_active', true)
            ->distinct()
            ->pluck('endorser_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        return [
            'total' => $orgs->count(),
            'endorsing' => $orgs->filter(fn (Organization $o) => in_array((string) $o->id, $endorserIds, true))->count(),
            'in_codetermination' => $orgs->filter(fn (Organization $o) => (int) $o->worker_count >= $min)->count(),
            'cgcs' => $orgs->filter(fn (Organization $o) => (bool) $o->is_cgc)->count(),
        ];
    }

    // -------------------------------------------------------------------------
    // OrgDetail helpers (§B.7)
    // -------------------------------------------------------------------------

    /** @return array{member?: int, shareholder?: int, partner?: int} */
    private function memberCounts(Organization $org): array
    {
        $byKind = OrgMembership::query()
            ->where('organization_id', $org->id)
            ->where('status', OrgMembership::STATUS_ACTIVE)
            ->groupBy('kind')
            ->select('kind', DB::raw('count(*) as n'))
            ->pluck('n', 'kind');

        $counts = [];
        foreach ([OrgMembership::KIND_MEMBER, OrgMembership::KIND_SHAREHOLDER, OrgMembership::KIND_PARTNER] as $kind) {
            if ($byKind->has($kind)) {
                $counts[$kind] = (int) $byKind->get($kind);
            }
        }

        return $counts;
    }

    /**
     * OwnershipPanel props (§A.4): structure rule, the OPEN cap table, the
     * member/worker counts. CGC never reaches here (the show() 302).
     *
     * @param  array<string, int>  $memberCounts
     * @return array<string, mixed>
     */
    private function ownershipProps(Organization $org, array $memberCounts, int $workerCount): array
    {
        $stakes = OrgOwnershipStake::query()
            ->where('organization_id', $org->id)
            ->open()
            ->orderByDesc('units')
            ->get()
            ->map(fn (OrgOwnershipStake $stake) => [
                'holder' => ['type' => $stake->holder_type, 'name' => $this->stakeHolderName($stake), 'href' => null],
                'units' => (float) $stake->units,
                'pct' => $stake->pct !== null ? (float) $stake->pct : null,
            ])
            ->all();

        // OwnershipPanel reads { members, shareholders, partners, workers };
        // map the singular membership kinds to its plural keys.
        $panelCounts = [];
        if (isset($memberCounts[OrgMembership::KIND_MEMBER])) {
            $panelCounts['members'] = $memberCounts[OrgMembership::KIND_MEMBER];
        }
        if (isset($memberCounts[OrgMembership::KIND_SHAREHOLDER])) {
            $panelCounts['shareholders'] = $memberCounts[OrgMembership::KIND_SHAREHOLDER];
        }
        if (isset($memberCounts[OrgMembership::KIND_PARTNER])) {
            $panelCounts['partners'] = $memberCounts[OrgMembership::KIND_PARTNER];
        }
        $panelCounts['workers'] = $workerCount;

        return [
            'structure' => $org->structure,
            'isCgc' => false,
            'stakes' => $stakes,
            'memberCounts' => $panelCounts,
            'structureHistory' => [],
        ];
    }

    private function stakeHolderName(OrgOwnershipStake $stake): string
    {
        if ($stake->holder_type === OrgOwnershipStake::HOLDER_USERS) {
            $name = DB::table('users')->where('id', $stake->holder_id)->value('display_name')
                ?? DB::table('users')->where('id', $stake->holder_id)->value('name');

            return $name !== null ? (string) $name : 'Holder';
        }

        if ($stake->holder_type === OrgOwnershipStake::HOLDER_ORGANIZATIONS) {
            return (string) (DB::table('organizations')->where('id', $stake->holder_id)->value('name') ?? 'Organization');
        }

        return (string) (DB::table('jurisdictions')->where('id', $stake->holder_id)->value('name') ?? 'Jurisdiction');
    }

    /**
     * Board summary props: the compact BoardStrip rows + the CoDetScale
     * snapshot. worker_seats / composition_valid are engine outputs from
     * the boards row; nextStepAt is the service projection.
     *
     * @return array<string, mixed>|null
     */
    private function boardProps(Organization $org, ?Board $board, int $workerCount, int $min, int $parity): ?array
    {
        if ($board === null) {
            return ['exists' => false, 'strip' => null, 'codet' => null, 'elections_href' => null, 'codet_href' => null];
        }

        $ownerSeats = (int) $board->owner_seats;
        $workerSeats = (int) $board->worker_seats;

        return [
            'exists' => true,
            'strip' => [
                'seats' => $this->boardSeatRows($board),
                'compositionValid' => (bool) $board->composition_valid,
                'requiredWorkerSeats' => $workerSeats,
            ],
            'codet' => [
                'workers' => $workerCount,
                'ownerSeats' => $ownerSeats,
                'workerSeats' => $workerSeats,
                'thresholds' => ['min' => $min, 'parity' => $parity],
                'nextStepAt' => CoDeterminationService::nextStep($workerSeats, $ownerSeats, $min, $parity),
                'compositionValid' => (bool) $board->composition_valid,
            ],
            'elections_href' => '/organizations/'.$org->id.'/board-elections',
            'codet_href' => '/organizations/co-determination?org='.$org->id,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function boardSeatRows(Board $board): array
    {
        return $board->seats
            ->sortBy('seat_no')
            ->map(fn (BoardSeat $seat) => [
                'id' => (string) $seat->id,
                'seat_class' => $seat->seat_class,
                'holder' => $seat->holder !== null
                    ? ['name' => $seat->holder->display_name ?? $seat->holder->name]
                    : null,
                'is_chair' => (bool) $seat->is_chair,
                'status' => $seat->status,
                'term' => $seat->term !== null ? [
                    'starts_on' => $seat->term->starts_on,
                    'ends_on' => $seat->term->ends_on,
                    'clock' => $seat->seat_class === BoardSeat::CLASS_WORKER_ELECTED ? 'CLK-10' : 'CLK-09',
                ] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * The endorsement handshake (§B.7): incoming pending requests (R-23
     * decides) + the granted list.
     *
     * @return array<string, mixed>
     */
    private function endorsementProps(Organization $org): array
    {
        $requests = EndorsementRequest::query()
            ->where('organization_id', $org->id)
            ->with(['candidacy.user:id,name,display_name', 'candidacy.race:id,election_id'])
            ->orderByDesc('requested_at')
            ->get();

        $incoming = $requests
            ->where('status', EndorsementRequest::STATUS_PENDING)
            ->map(fn (EndorsementRequest $r) => [
                'id' => (string) $r->id,
                'candidate' => [
                    'name' => $r->candidacy?->user?->display_name ?? $r->candidacy?->user?->name ?? 'Candidate',
                    'href' => $r->candidacy_id !== null ? '/candidacies/'.$r->candidacy_id : null,
                ],
                'requested_at' => $r->requested_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $granted = $requests
            ->where('status', EndorsementRequest::STATUS_GRANTED)
            ->map(fn (EndorsementRequest $r) => [
                'candidate' => [
                    'name' => $r->candidacy?->user?->display_name ?? $r->candidacy?->user?->name ?? 'Candidate',
                    'href' => $r->candidacy_id !== null ? '/candidacies/'.$r->candidacy_id : null,
                ],
                'granted_at' => $r->decided_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'incoming' => $incoming,
            'granted' => $granted,
            'total' => count($granted),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function documentRows(Organization $org): array
    {
        return OrgDocumentPackage::query()
            ->where('organization_id', $org->id)
            ->withCount('versions')
            ->orderBy('name')
            ->get()
            ->map(function (OrgDocumentPackage $pkg) {
                $latest = $pkg->versions()->max('version_no');

                return [
                    'package' => $pkg->name,
                    'key' => $pkg->key,
                    'kind' => $pkg->kind,
                    'version' => $latest !== null ? (int) $latest : 0,
                    'status' => $pkg->status,
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function contractRows(Organization $org): array
    {
        return OrgContract::query()
            ->where('organization_id', $org->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (OrgContract $c) => [
                'id' => (string) $c->id,
                'title' => $this->contractTitle($c),
                'kind' => $c->kind,
                'counterparty' => $this->counterpartyName($c),
                'signed_a' => $c->signed_by_org_at !== null,
                'signed_b' => $c->signed_by_counterparty_at !== null,
                'status' => $c->status,
                'feeds_headcount' => $c->kind === OrgContract::KIND_LABOR_RECURRING,
            ])
            ->values()
            ->all();
    }

    private function contractTitle(OrgContract $c): string
    {
        return match ($c->kind) {
            OrgContract::KIND_LABOR_RECURRING => 'Recurring labor contract',
            OrgContract::KIND_LABOR_SINGLE => 'Single labor contract',
            OrgContract::KIND_COMMERCIAL => 'Commercial contract',
            default => 'Contract',
        };
    }

    private function counterpartyName(OrgContract $c): string
    {
        if ($c->counterparty_type === OrgContract::COUNTERPARTY_USERS) {
            $name = DB::table('users')->where('id', $c->counterparty_id)->value('display_name')
                ?? DB::table('users')->where('id', $c->counterparty_id)->value('name');

            return $name !== null ? (string) $name : 'Counterparty';
        }

        return (string) (DB::table('organizations')->where('id', $c->counterparty_id)->value('name') ?? 'Organization');
    }

    /** @return array{kind: string}|null */
    private function myMembership(Organization $org, string $userId): ?array
    {
        $row = OrgMembership::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $userId)
            ->whereIn('status', [OrgMembership::STATUS_APPLIED, OrgMembership::STATUS_ACTIVE])
            ->orderByDesc('applied_at')
            ->first();

        return $row !== null ? ['kind' => $row->kind, 'status' => $row->status] : null;
    }

    /** @return array{since: string|null}|null */
    private function myWorker(Organization $org, string $userId): ?array
    {
        $row = OrgWorker::query()
            ->forEmployer(OrgWorker::EMPLOYER_ORGANIZATIONS, (string) $org->id)
            ->where('user_id', $userId)
            ->whereIn('status', [OrgWorker::STATUS_APPLIED, OrgWorker::STATUS_ACTIVE])
            ->orderByDesc('created_at')
            ->first();

        return $row !== null
            ? ['since' => $row->started_at?->toIso8601String(), 'status' => $row->status]
            : null;
    }

    // -------------------------------------------------------------------------

    /**
     * Resolve worker_rep_min/parity_employees through the nearest viewer
     * association (CLK-13/14 are amendable; defaults match the hardened
     * constants). Falls back to the constitutional defaults when no
     * association is available.
     *
     * @param  list<array{id: string}>  $associations
     * @return array{0: int, 1: int}
     */
    private function resolveThresholds(array $associations): array
    {
        $jurisdictionId = $associations[0]['id'] ?? null;

        if ($jurisdictionId === null) {
            return [100, 2000];
        }

        return [
            $this->settings->resolveInt($jurisdictionId, 'worker_rep_min_employees', 100),
            $this->settings->resolveInt($jurisdictionId, 'worker_rep_parity_employees', 2000),
        ];
    }
}
