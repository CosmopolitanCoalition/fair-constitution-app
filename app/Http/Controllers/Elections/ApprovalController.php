<?php

namespace App\Http\Controllers\Elections;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Support\RaceFootprint;
use App\Http\Controllers\Controller;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\User;
use App\Services\ApprovalService;
use App\Support\ApprovalDirectory;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-B4 — OpenBallot (PHASE_B_DESIGN_frontend.md §B.4) + the approve/
 * revoke endpoints (design §D).
 *
 *   GET    /elections/{election}/open-ballot[?race=][&q=][&cursor=]
 *   POST   /elections/{election}/approvals   {candidacy_id}
 *   DELETE /elections/{election}/approvals/{candidacy}
 *
 * Approvals are engine ACTIONS, deliberately not forms (no F-ID): they
 * route through ApprovalService, which enforces the CLK-18 window and the
 * secrecy contract (zero per-approval audit entries — the audited event is
 * the daily rollup). The footprint gate (Art. I — approving requires
 * jurisdictional association in the race) is enforced HERE, and its
 * rejection is also never chain-recorded: a rejected approval linking
 * user → candidacy would breach the same secrecy.
 *
 * Standings are read EXCLUSIVELY from approval_standings (the daily
 * aggregate / frozen cutoff snapshot) — never a live COUNT(*) — so the
 * viewer's own action can never move the public number (§A.2 delta note).
 */
class ApprovalController extends Controller
{
    public function __construct(
        private readonly ApprovalService $approvals,
    ) {}

    // =========================================================================
    // GET /elections/{election}/open-ballot
    // =========================================================================

    public function show(Request $request, string $election): Response
    {
        $model = Election::query()
            ->with(['jurisdiction', 'races.jurisdiction', 'races.district'])
            ->findOrFail($election);

        $user = $request->user();
        $phase = ElectionController::phase($model->status);

        $race = $this->resolveRace($user, $model, $request->query('race'));

        $directory = app(ApprovalDirectory::class);
        $page = $race === null ? [
            'standings' => [], 'myApprovals' => [], 'filters' => [], 'asOf' => null,
            'total' => 0, 'myActiveApprovals' => 0, 'pagination' => [],
        ] : $directory->page($request, $race, $user);

        $inFootprint = $user !== null && $race !== null
            && RaceFootprint::userInFootprint((string) $user->getKey(), $race);

        return Inertia::render('Elections/OpenBallot', [
            'surface' => SurfaceMeta::for('elections/open-ballot'),
            'race' => $race === null ? null : [
                'id' => (string) $race->id,
                'election_id' => (string) $model->id,
                'label' => ElectionController::raceLabel($race),
                'seats' => (int) $race->seats,
                'finalist_count' => (int) $race->finalist_count,
                'phase' => $phase,
                'asOf' => $page['asOf'],
            ],
            'races' => $model->races->count() > 1
                ? $model->races
                    ->sortBy(fn (ElectionRace $r) => [$r->seat_kind, $r->district?->district_number ?? PHP_INT_MAX])
                    ->values()
                    ->map(fn (ElectionRace $r) => [
                        'id' => (string) $r->id,
                        'label' => ElectionController::raceLabel($r),
                    ])
                    ->all()
                : [],
            'stats' => [
                'seats' => (int) ($race?->seats ?? 0),
                'finalistPlaces' => (int) ($race?->finalist_count ?? 0),
                'validatedCandidates' => $page['total'],
                'myActiveApprovals' => $page['myActiveApprovals'],
            ],
            'standings' => $page['standings'],
            'pagination' => $page['pagination'],
            'directoryNotice' => $page['notice'] ?? null,
            'myApprovals' => $page['myApprovals'],
            'filters' => $page['filters'],
            'endorsementDetails' => Inertia::optional(fn () => $race === null ? null : $directory->endorsements($request, $race)),
            'approvable' => $inFootprint && $model->status === Election::STATUS_APPROVAL_OPEN,
            'inFootprint' => $inFootprint,
        ]);
    }

    // =========================================================================
    // POST /elections/{election}/approvals — cast (revocable)
    // =========================================================================

    public function store(Request $request, string $election): RedirectResponse
    {
        $model = Election::query()->findOrFail($election);

        $validated = $request->validate([
            'candidacy_id' => ['required', 'uuid'],
        ]);

        $candidacy = Candidacy::query()
            ->where('election_id', $model->id)
            ->findOrFail($validated['candidacy_id']);

        $this->assertFootprint($request->user(), $candidacy);

        $this->approvals->cast($request->user(), $candidacy);

        return back()->with('status', __('Approved — revocable until the finalist cutoff.'));
    }

    // =========================================================================
    // DELETE /elections/{election}/approvals/{candidacy} — revoke
    // =========================================================================

    public function destroy(Request $request, string $election, string $candidacy): RedirectResponse
    {
        $model = Election::query()->findOrFail($election);

        $row = Candidacy::query()
            ->where('election_id', $model->id)
            ->findOrFail($candidacy);

        // Revocation is symmetric and unceremonious (design §D) — same
        // window, same footprint, no confirm step anywhere.
        $this->assertFootprint($request->user(), $row);

        $this->approvals->revoke($request->user(), $row);

        return back()->with('status', __('Approval withdrawn.'));
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Art. I footprint gate: approving requires an active association
     * inside the candidacy's race footprint. NOT chain-recorded on
     * rejection (see class docblock).
     */
    private function assertFootprint(?User $user, Candidacy $candidacy): void
    {
        if ($candidacy->race_id === null) {
            throw new ConstitutionalViolation(
                'This candidacy is not yet bound to a race (awaiting board validation) — approvals open once it is in the pool.',
                'Art. II §2 · CGA open-ballot spec'
            );
        }

        $inFootprint = $user !== null && RaceFootprint::bestRaceForUser(
            (string) $user->getKey(),
            (string) $candidacy->election_id,
            (string) $candidacy->race_id,
        ) !== null;

        if (! $inFootprint) {
            throw new ConstitutionalViolation(
                'Approving requires jurisdictional association in this race — you can browse, not approve, here.',
                'Art. I'
            );
        }
    }

    /**
     * Resolve the race to show: explicit ?race= (browsing) → the viewer's
     * own footprint race → the election's first race. Single-race
     * elections skip the picker entirely (§B race-resolution rule).
     */
    private function resolveRace(?User $user, Election $election, ?string $raceParam): ?ElectionRace
    {
        $races = $election->races;

        if ($races->isEmpty()) {
            return null;
        }

        if ($races->count() === 1) {
            return $races->first();
        }

        if ($raceParam !== null) {
            $explicit = $races->firstWhere('id', $raceParam);
            if ($explicit !== null) {
                return $explicit;
            }
        }

        if ($user !== null) {
            $best = RaceFootprint::bestRaceForUser((string) $user->getKey(), (string) $election->id);
            if ($best !== null) {
                $own = $races->firstWhere('id', (string) $best->race_id);
                if ($own !== null) {
                    return $own;
                }
            }
        }

        return $races
            ->sortBy(fn (ElectionRace $r) => [$r->seat_kind, $r->district?->district_number ?? PHP_INT_MAX])
            ->first();
    }
}
