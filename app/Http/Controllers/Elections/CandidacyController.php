<?php

namespace App\Http\Controllers\Elections;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\User;
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
 *   GET   /candidates/{candidacy}                    — 302 → the person profile's Candidacy tab (v3.2 0a)
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
        private readonly RoleService $roles,
    ) {}

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
            Candidacy::STATUS_REJECTED => 1, // registered → rejected
            Candidacy::STATUS_WITHDRAWN => 3, // … in_pool → withdrawn
            Candidacy::STATUS_NON_FINALIST => 3, // … in_pool → non_finalist (write-in eligible)
            Candidacy::STATUS_DEFEATED => 4, // … finalist → defeated
            default => null,
        };

        return [
            'machine' => $branch === null ? $happy : [...array_slice($happy, 0, $branch), $status],
            'current' => $status,
        ];
    }

    // =========================================================================
    // GET /elections/{election}/candidacy — F-IND-011 (§B.2)
    // =========================================================================

    public function create(Request $request, string $election): Response|RedirectResponse
    {
        $model = Election::query()
            ->with(['jurisdiction', 'races.jurisdiction', 'races.district'])
            ->findOrFail($election);

        // Org board candidacy is class-gated (worker/owner membership) and
        // belongs to the Organizations board-elections surface — NOT this
        // public "Right to Stand" page (F-IND-011, Art. I). Bounce a stray
        // link back to the legislative candidacy entry.
        if (in_array($model->kind, [Election::KIND_ORG_BOARD_OWNER, Election::KIND_ORG_BOARD_WORKER], true)) {
            return redirect()->route('elections.entry.candidacy')->with(
                'status',
                'Board seats are stood for from the organization\'s board-elections page — the worker or owner class '
                .'decides eligibility there, not the open right to stand for public office (Art. III §6).'
            );
        }

        $user = $request->user();

        $mine = Candidacy::query()
            ->with(['race.jurisdiction', 'race.district'])
            ->where('election_id', $model->id)
            ->where('user_id', (string) $user->getKey())
            ->first();

        return Inertia::render('Elections/CandidacyRegistration', [
            'surface' => SurfaceMeta::for('elections/candidacy-registration'),
            'election' => [
                'id' => (string) $model->id,
                'jurisdiction_name' => $model->jurisdiction?->name,
                'finalist_cutoff_at' => $model->finalist_cutoff_at?->toIso8601String(),
            ],
            'phase' => ElectionController::phase($model->status),
            // CLK-18: registration is open exactly while the approval phase is.
            'registrationOpen' => $model->status === Election::STATUS_APPROVAL_OPEN,
            'offices' => $this->officesFor($user, $model),
            'tagVocabulary' => config('cga.position_tag_vocabulary', []),
            'machine' => self::machine(),
            'viewerAssociated' => in_array('R-03', $this->roles->rolesFor($user), true),
            'myCandidacy' => $mine === null ? null : [
                'id' => (string) $mine->id,
                'status' => $mine->status,
                'office_label' => $mine->race !== null
                    ? ElectionController::raceLabel($mine->race)
                    : 'Awaiting race binding (F-ELB-002)',
                'validated_at' => $mine->validated_at?->toIso8601String(),
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
            'race_id' => ['required', 'uuid',
                Rule::exists('election_races', 'id')->where('election_id', $model->id)],
            'platform_statement' => ['nullable', 'string', 'max:10000'],
            'position_tags' => ['sometimes', 'array', 'max:20'],
            'position_tags.*' => ['string', Rule::in(config('cga.position_tag_vocabulary', []))],
            'residency_attested' => ['required', 'accepted'],
        ]);

        $this->engine->file('F-IND-011', $request->user(), [
            'election_id' => (string) $model->id,
            'jurisdiction_id' => (string) $model->jurisdiction_id,
            'platform_statement' => $validated['platform_statement'] ?? null,
            'position_tags' => array_values($validated['position_tags'] ?? []),
            'residency_attested' => true,
        ]);

        return back()->with('status', 'Candidacy registered — awaiting board validation (F-ELB-002; residency is the only check).');
    }

    // =========================================================================
    // GET /candidates/{candidacy} — forwards to the ONE person profile
    // =========================================================================

    /**
     * v3.2 ruling 0a: a candidate page is the Candidacy tab of the one
     * person profile now (mockups/v3/electoral/candidate-profile.html is
     * the same redirect on the spec side). Every profile_href emitted by
     * the count/ballot pages lands here and forwards; the panel assembly
     * this method used to carry lives in CandidacyPanel (Http/Presenters).
     */
    public function show(Request $request, string $candidacy): RedirectResponse
    {
        $model = Candidacy::query()->findOrFail($candidacy);

        return redirect()->route('people.show', [
            'who' => (string) $model->user_id,
            'tab' => 'candidacy',
            'candidacy' => (string) $model->id,
        ]);
    }

    /** PATCH /candidates/{candidacy} — F-CAN-001 (statement / tags). */
    public function update(Request $request, string $candidacy): RedirectResponse
    {
        $model = Candidacy::query()->findOrFail($candidacy);

        $validated = $request->validate([
            'platform_statement' => ['nullable', 'string', 'max:10000'],
            'position_tags' => ['sometimes', 'array', 'max:20'],
            'position_tags.*' => ['string', Rule::in(config('cga.position_tag_vocabulary', []))],
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
            'candidacy_id' => (string) $model->id,
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
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->engine->file('F-CAN-002', $request->user(), [
            'candidacy_id' => (string) $model->id,
            'organization_id' => $validated['organization_id'],
            'message' => $validated['message'] ?? null,
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
                'race_id' => (string) $r->id,
                'label' => ElectionController::raceLabel($r),
                'seats' => (int) $r->seats,
            ])
            ->all();
    }

}
