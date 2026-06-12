<?php

namespace App\Http\Controllers\Elections;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\Endorsement;
use App\Models\EndorsementRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\RoleService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-B3 — CandidacyRegistration + CandidateProfile
 * (PHASE_B_DESIGN_frontend.md §B.2/§B.3).
 *
 *   GET   /elections/{election}/candidacy            — F-IND-011 form
 *   POST  /elections/{election}/candidacy            — file F-IND-011 (R-03)
 *   GET   /candidates/{candidacy}                    — public profile
 *   PATCH /candidates/{candidacy}                    — file F-CAN-001 (owner)
 *   POST  /candidates/{candidacy}/withdraw           — file F-CAN-003 (owner; ballot lock)
 *   POST  /candidates/{candidacy}/endorsement-requests — file F-CAN-002 (owner)
 *
 * Every write goes through ConstitutionalEngine::file — UI disabling is
 * UX, never the boundary; the engine independently 422s with citation.
 */
class CandidacyController extends Controller
{
    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly ApprovalService $approvals,
        private readonly RoleService $roles,
    ) {
    }

    /** ESM-06 happy path (PHP-owned machine — §B conventions). */
    public static function machine(): array
    {
        return [
            Candidacy::STATUS_REGISTERED,
            Candidacy::STATUS_VALIDATED,
            Candidacy::STATUS_IN_POOL,
            Candidacy::STATUS_FINALIST,
            Candidacy::STATUS_ELECTED,
        ];
    }

    /**
     * Machine + current node for a concrete candidacy: off-path terminals
     * (rejected / withdrawn / non_finalist / defeated) truncate the happy
     * path at their branch point and append themselves, so the StateStrip
     * shows the path actually taken.
     *
     * @return array{machine: list<string>, current: string}
     */
    public static function machineFor(string $status): array
    {
        $happy = self::machine();

        $branch = match ($status) {
            Candidacy::STATUS_REJECTED     => 1, // registered → rejected
            Candidacy::STATUS_WITHDRAWN    => 3, // … in_pool → withdrawn
            Candidacy::STATUS_NON_FINALIST => 3, // … in_pool → non_finalist (write-in eligible)
            Candidacy::STATUS_DEFEATED     => 4, // … finalist → defeated
            default                        => null,
        };

        return [
            'machine' => $branch === null ? $happy : [...array_slice($happy, 0, $branch), $status],
            'current' => $status,
        ];
    }

    // =========================================================================
    // GET /elections/{election}/candidacy — F-IND-011 (§B.2)
    // =========================================================================

    public function create(Request $request, string $election): Response
    {
        $model = Election::query()
            ->with(['jurisdiction', 'races.jurisdiction', 'races.district'])
            ->findOrFail($election);

        $user = $request->user();

        $mine = Candidacy::query()
            ->with(['race.jurisdiction', 'race.district'])
            ->where('election_id', $model->id)
            ->where('user_id', (string) $user->getKey())
            ->first();

        return Inertia::render('Elections/CandidacyRegistration', [
            'surface'          => SurfaceMeta::for('elections/candidacy-registration'),
            'election'         => [
                'id'                 => (string) $model->id,
                'jurisdiction_name'  => $model->jurisdiction?->name,
                'finalist_cutoff_at' => $model->finalist_cutoff_at?->toIso8601String(),
            ],
            'phase'            => ElectionController::phase($model->status),
            // CLK-18: registration is open exactly while the approval phase is.
            'registrationOpen' => $model->status === Election::STATUS_APPROVAL_OPEN,
            'offices'          => $this->officesFor($user, $model),
            'tagVocabulary'    => config('cga.position_tag_vocabulary', []),
            'machine'          => self::machine(),
            'viewerAssociated' => in_array('R-03', $this->roles->rolesFor($user), true),
            'myCandidacy'      => $mine === null ? null : [
                'id'               => (string) $mine->id,
                'status'           => $mine->status,
                'office_label'     => $mine->race !== null
                    ? ElectionController::raceLabel($mine->race)
                    : 'Awaiting race binding (F-ELB-002)',
                'validated_at'     => $mine->validated_at?->toIso8601String(),
                'rejection_reason' => $mine->rejection_reason,
            ],
        ]);
    }

    /** POST /elections/{election}/candidacy — file F-IND-011 through the engine. */
    public function store(Request $request, string $election): RedirectResponse
    {
        $model = Election::query()->findOrFail($election);

        $validated = $request->validate([
            // The office select is presentational (race binding is the
            // board's F-ELB-002 act); it must still be a race of THIS
            // election so the form cannot point elsewhere.
            'race_id'            => ['required', 'uuid',
                Rule::exists('election_races', 'id')->where('election_id', $model->id)],
            'platform_statement' => ['nullable', 'string', 'max:10000'],
            'position_tags'      => ['sometimes', 'array', 'max:20'],
            'position_tags.*'    => ['string', Rule::in(config('cga.position_tag_vocabulary', []))],
            'residency_attested' => ['required', 'accepted'],
        ]);

        $this->engine->file('F-IND-011', $request->user(), [
            'election_id'        => (string) $model->id,
            'jurisdiction_id'    => (string) $model->jurisdiction_id,
            'platform_statement' => $validated['platform_statement'] ?? null,
            'position_tags'      => array_values($validated['position_tags'] ?? []),
            'residency_attested' => true,
        ]);

        return back()->with('status', 'Candidacy registered — awaiting board validation (F-ELB-002; residency is the only check).');
    }

    // =========================================================================
    // GET /candidates/{candidacy} — public profile (§B.3)
    // =========================================================================

    public function show(Request $request, string $candidacy): Response
    {
        $model = Candidacy::query()
            ->with(['user', 'election.jurisdiction', 'race.jurisdiction', 'race.district'])
            ->findOrFail($candidacy);

        $user     = $request->user();
        $election = $model->election;
        $race     = $model->race;
        $phase    = ElectionController::phase($election->status);
        $isOwner  = $user !== null && (string) $user->getKey() === (string) $model->user_id;

        ['machine' => $machine, 'current' => $current] = self::machineFor($model->status);

        return Inertia::render('Elections/CandidateProfile', [
            'surface'   => SurfaceMeta::for('elections/candidate-profile'),
            'candidacy' => [
                'id'            => (string) $model->id,
                'name'          => $model->user?->display_name ?? $model->user?->name ?? 'Candidate',
                'statement'     => $model->platform_statement,
                'position_tags' => $model->position_tags ?? [],
                'status'        => $model->status,
                'withdrawn'     => $model->status === Candidacy::STATUS_WITHDRAWN,
                'incumbent'     => $this->isIncumbent($model),
                'race'          => $race === null ? null : [
                    'id'             => (string) $race->id,
                    'election_id'    => (string) $election->id,
                    'label'          => ElectionController::raceLabel($race),
                    'seats'          => (int) $race->seats,
                    'finalist_count' => (int) $race->finalist_count,
                    'phase'          => $phase,
                ],
            ],
            'standing'     => $race === null ? null : $this->standingFor($model, $race, $phase),
            'machine'      => $machine,
            'currentState' => $current,
            'endorsements' => $this->endorsementsFor($model),
            'requests'     => $isOwner ? $this->requestsFor($model) : [],
            'publicRecord' => $this->publicRecordFor($model),
            'isOwner'      => $isOwner,
            'can'          => [
                // Ballot lock (CLK-21): withdrawal closes at the finalist
                // cutoff — mirrored client-side as disabled-with-citation,
                // enforced server-side by the F-CAN-003 handler.
                'withdraw' => $isOwner
                    && $election->status === Election::STATUS_APPROVAL_OPEN
                    && in_array($model->status, [
                        Candidacy::STATUS_REGISTERED,
                        Candidacy::STATUS_VALIDATED,
                        Candidacy::STATUS_IN_POOL,
                        Candidacy::STATUS_FINALIST,
                    ], true)
                    && ($election->finalist_cutoff_at === null || $election->finalist_cutoff_at->isFuture()),
            ],
            'organizations' => $isOwner
                ? Organization::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->limit(100)
                    ->get(['id', 'name'])
                    ->map(fn (Organization $o) => ['id' => (string) $o->id, 'name' => $o->name])
                    ->all()
                : [],
        ]);
    }

    /** PATCH /candidates/{candidacy} — F-CAN-001 (statement / tags). */
    public function update(Request $request, string $candidacy): RedirectResponse
    {
        $model = Candidacy::query()->findOrFail($candidacy);

        $validated = $request->validate([
            'platform_statement' => ['nullable', 'string', 'max:10000'],
            'position_tags'      => ['sometimes', 'array', 'max:20'],
            'position_tags.*'    => ['string', Rule::in(config('cga.position_tag_vocabulary', []))],
        ]);

        $payload = ['candidacy_id' => (string) $model->id, 'jurisdiction_id' => (string) $model->election?->jurisdiction_id];

        if ($request->has('platform_statement')) {
            $payload['platform_statement'] = $validated['platform_statement'] ?? null;
        }
        if ($request->has('position_tags')) {
            $payload['position_tags'] = array_values($validated['position_tags'] ?? []);
        }

        $this->engine->file('F-CAN-001', $request->user(), $payload);

        return back()->with('status', 'Campaign profile updated — the change is on the public record.');
    }

    /** POST /candidates/{candidacy}/withdraw — F-CAN-003 (ballot lock at CLK-21). */
    public function withdraw(Request $request, string $candidacy): RedirectResponse
    {
        $model = Candidacy::query()->findOrFail($candidacy);

        $this->engine->file('F-CAN-003', $request->user(), [
            'candidacy_id'    => (string) $model->id,
            'jurisdiction_id' => (string) $model->election?->jurisdiction_id,
        ]);

        return back()->with('status', 'Candidacy withdrawn — recorded permanently on the public record.');
    }

    /** POST /candidates/{candidacy}/endorsement-requests — F-CAN-002. */
    public function requestEndorsement(Request $request, string $candidacy): RedirectResponse
    {
        $model = Candidacy::query()->findOrFail($candidacy);

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid'],
            'message'         => ['nullable', 'string', 'max:2000'],
        ]);

        $this->engine->file('F-CAN-002', $request->user(), [
            'candidacy_id'    => (string) $model->id,
            'organization_id' => $validated['organization_id'],
            'message'         => $validated['message'] ?? null,
            'jurisdiction_id' => (string) $model->election?->jurisdiction_id,
        ]);

        return back()->with('status', 'Endorsement requested — the organization\'s agent decides via F-ORG-002.');
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * §B.2 office list: ONLY races whose footprint contains one of the
     * viewer's active associations (district members via
     * legislature_district_jurisdictions; at-large via the race's own
     * jurisdiction — same footprint rule as RaceFootprint).
     *
     * @return list<array{election_id: string, race_id: string, label: string, seats: int}>
     */
    private function officesFor(User $user, Election $election): array
    {
        $raceIds = DB::select(
            'SELECT DISTINCT er.id
             FROM election_races er
             LEFT JOIN legislature_district_jurisdictions ldj ON ldj.district_id = er.district_id
             JOIN residency_confirmations rc
                ON rc.user_id = ?
               AND rc.is_active = true
               AND rc.jurisdiction_id = COALESCE(ldj.jurisdiction_id, er.jurisdiction_id)
             WHERE er.election_id = ?
               AND er.deleted_at IS NULL',
            [(string) $user->getKey(), (string) $election->id]
        );

        $ids = array_map(fn ($row) => (string) $row->id, $raceIds);

        return $election->races
            ->filter(fn (ElectionRace $r) => in_array((string) $r->id, $ids, true))
            ->sortBy(fn (ElectionRace $r) => [$r->seat_kind, $r->district?->district_number ?? PHP_INT_MAX])
            ->values()
            ->map(fn (ElectionRace $r) => [
                'election_id' => (string) $election->id,
                'race_id'     => (string) $r->id,
                'label'       => ElectionController::raceLabel($r),
                'seats'       => (int) $r->seats,
            ])
            ->all();
    }

    /**
     * §B.3 standing card data from the daily aggregate (never a live
     * count): rank/of/approvals + the finalist-line and top values for the
     * ThresholdMeter. Null when the race has no standings rows for this
     * candidacy yet — the page renders the "see the count" card.
     */
    private function standingFor(Candidacy $candidacy, ElectionRace $race, string $phase): ?array
    {
        $standings = $this->approvals->standings($race);

        if ($standings->isEmpty()) {
            return null;
        }

        $mine = $standings->firstWhere('candidacy_id', $candidacy->id);

        if ($mine === null) {
            return null;
        }

        $line = (int) min($race->finalist_count, $standings->count());
        $lineRow = $standings->firstWhere('rank', $line);

        $isFinalist = $phase === 'approval'
            ? (int) $mine->rank <= (int) $race->finalist_count
            : in_array($candidacy->status, [Candidacy::STATUS_FINALIST, Candidacy::STATUS_ELECTED, Candidacy::STATUS_DEFEATED], true);

        return [
            'rank'          => (int) $mine->rank,
            'of'            => $standings->count(),
            'approvals'     => (int) $mine->approvals_count,
            'isFinalist'    => $isFinalist,
            'lineApprovals' => (int) ($lineRow?->approvals_count ?? 0),
            'topApprovals'  => (int) ($standings->firstWhere('rank', 1)?->approvals_count ?? $mine->approvals_count),
            'frozen'        => (bool) $mine->is_frozen,
            'asOf'          => $mine->as_of_date?->toDateString(),
        ];
    }

    /** §B.3 endorsements: org chips + individual split + the public web. */
    private function endorsementsFor(Candidacy $candidacy): array
    {
        $orgRows = Endorsement::query()
            // Qualified by hand (the active() scope's columns would be
            // ambiguous across the organizations join).
            ->where('endorsements.is_active', true)
            ->whereNull('endorsements.withdrawn_at')
            ->where('endorsements.candidate_id', $candidacy->id)
            ->where('endorsements.endorser_type', Endorsement::ENDORSER_ORGANIZATION)
            ->join('organizations as o', 'o.id', '=', 'endorsements.endorser_id')
            ->orderBy('endorsements.endorsed_at')
            ->get(['o.id', 'o.name', 'o.type', 'endorsements.endorsed_at']);

        $individuals = Endorsement::query()
            ->active()
            ->where('candidate_id', $candidacy->id)
            ->where('endorser_type', Endorsement::ENDORSER_USER)
            ->get(['endorser_id', 'is_public']);

        $publicIds = $individuals->where('is_public', true)->pluck('endorser_id')->map(fn ($id) => (string) $id);

        // The expandable public web: each public endorser + their OTHER
        // public endorsements in this election (profile links).
        $publicWeb = [];

        if ($publicIds->isNotEmpty()) {
            $users = User::query()->whereIn('id', $publicIds)->get(['id', 'name', 'display_name']);

            $webRows = Endorsement::query()
                ->where('endorsements.is_active', true)
                ->whereNull('endorsements.withdrawn_at')
                ->where('endorsements.is_public', true)
                ->where('endorsements.election_id', $candidacy->election_id)
                ->where('endorsements.endorser_type', Endorsement::ENDORSER_USER)
                ->whereIn('endorsements.endorser_id', $publicIds)
                ->join('candidacies as c', 'c.id', '=', 'endorsements.candidate_id')
                ->join('users as cu', 'cu.id', '=', 'c.user_id')
                ->get(['endorsements.endorser_id', 'c.id as candidacy_id', 'cu.name as candidate_name', 'cu.display_name as candidate_display_name']);

            $candidateUserIds = Candidacy::query()
                ->where('election_id', $candidacy->election_id)
                ->pluck('user_id')
                ->map(fn ($id) => (string) $id);

            $publicWeb = $users->map(fn (User $u) => [
                'name'          => $u->display_name ?? $u->name,
                'user_id'       => (string) $u->id,
                'alsoCandidate' => $candidateUserIds->contains((string) $u->id),
                'endorses'      => $webRows
                    ->where('endorser_id', $u->id)
                    ->map(fn ($row) => [
                        'candidacy_id' => (string) $row->candidacy_id,
                        'name'         => $row->candidate_display_name ?? $row->candidate_name,
                    ])
                    ->values()
                    ->all(),
            ])->all();
        }

        return [
            'orgs' => $orgRows->map(fn ($row) => [
                'id'         => (string) $row->id,
                'name'       => $row->name,
                'type'       => $row->type,
                'granted_at' => $row->endorsed_at,
            ])->all(),
            'individual' => [
                'total'   => $individuals->count(),
                'public'  => $publicIds->count(),
                'private' => $individuals->count() - $publicIds->count(),
            ],
            'publicWeb' => $publicWeb,
        ];
    }

    /** @return list<array{org_name: string, requested_at: string|null, status: string}> */
    private function requestsFor(Candidacy $candidacy): array
    {
        return EndorsementRequest::query()
            ->with('organization')
            ->where('candidacy_id', $candidacy->id)
            ->orderByDesc('requested_at')
            ->get()
            ->map(fn (EndorsementRequest $r) => [
                'org_name'     => $r->organization?->name,
                'requested_at' => $r->requested_at?->toIso8601String(),
                'status'       => $r->status,
            ])
            ->all();
    }

    /**
     * §B.3 public record, Phase B slice: ACTIONS only — the candidate's
     * accepted elections/residency entries off the audit chain (the same
     * ledger My Record reads; ballot content is structurally never there).
     * Votes/statements arrive with the Phase C pipeline.
     */
    private function publicRecordFor(Candidacy $candidacy): array
    {
        $actions = AuditEntry::query()
            ->where('actor_user_id', (string) $candidacy->user_id)
            ->where('rejected', false)
            ->whereIn('module', ['elections', 'residency'])
            // Per-ping entries stay off the PUBLIC profile: ping cadence is
            // location-adjacent metadata (Art. I privacy posture). The
            // owner sees them on My Record; the public record carries the
            // milestone events only.
            ->where('event', 'not like', '%ping%')
            ->orderByDesc('seq')
            ->limit(20)
            ->get()
            ->map(fn (AuditEntry $entry) => [
                'seq'   => $entry->seq,
                'date'  => $entry->occurred_at?->toIso8601String(),
                'label' => $entry->event . ($entry->ref !== null ? " · {$entry->ref}" : ''),
            ])
            ->all();

        return [
            'votes'      => [],
            'actions'    => $actions,
            'statements' => [],
        ];
    }

    private function isIncumbent(Candidacy $candidacy): bool
    {
        $legislatureId = $candidacy->election?->legislature_id;

        if ($legislatureId === null) {
            return false;
        }

        return DB::table('legislature_members')
            ->where('legislature_id', (string) $legislatureId)
            ->where('user_id', (string) $candidacy->user_id)
            ->whereIn('status', ['elected', 'seated'])
            ->whereNull('deleted_at')
            ->exists();
    }
}
