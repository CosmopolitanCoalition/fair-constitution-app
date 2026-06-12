<?php

namespace App\Http\Controllers\Elections;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Support\RaceFootprint;
use App\Http\Controllers\Controller;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\Endorsement;
use App\Models\User;
use App\Services\ApprovalService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-B4 — OpenBallot (PHASE_B_DESIGN_frontend.md §B.4) + the approve/
 * revoke endpoints (design §D).
 *
 *   GET    /elections/{election}/open-ballot[?race=][&full=1]
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
    /** §B.4 payload budget: cap the initial standings payload, `?full=1` lifts. */
    private const STANDINGS_CAP_THRESHOLD = 200;
    private const STANDINGS_CAP = 100;

    public function __construct(
        private readonly ApprovalService $approvals,
    ) {
    }

    // =========================================================================
    // GET /elections/{election}/open-ballot
    // =========================================================================

    public function show(Request $request, string $election): Response
    {
        $model = Election::query()
            ->with(['jurisdiction', 'races.jurisdiction', 'races.district'])
            ->findOrFail($election);

        $user  = $request->user();
        $phase = ElectionController::phase($model->status);

        $race = $this->resolveRace($user, $model, $request->query('race'));

        $standings = $race === null ? collect() : $this->standingsFor($race);

        $inFootprint = $user !== null && $race !== null
            && RaceFootprint::userInFootprint((string) $user->getKey(), $race);

        $raceCandidacyIds = $standings->pluck('candidacy_id')->map(fn ($id) => (string) $id);

        $myApprovals = $user === null ? collect() : $this->approvals
            ->activeApprovalsFor($user, $model)
            ->pluck('candidacy_id')
            ->map(fn ($id) => (string) $id)
            ->filter(fn (string $id) => $raceCandidacyIds->contains($id))
            ->values();

        // Payload cap at Earth-district scale (§B.4 edge state). Ranks are
        // already full-race on each row, so truncation never moves the line.
        $total     = $standings->count();
        $truncated = false;

        if ($total > self::STANDINGS_CAP_THRESHOLD && $request->query('full') !== '1') {
            $standings = $standings->take(self::STANDINGS_CAP);
            $truncated = true;
        }

        $asOf = $standings->first()['asOf'] ?? null;

        return Inertia::render('Elections/OpenBallot', [
            'surface' => SurfaceMeta::for('elections/open-ballot'),
            'race'    => $race === null ? null : [
                'id'             => (string) $race->id,
                'election_id'    => (string) $model->id,
                'label'          => ElectionController::raceLabel($race),
                'seats'          => (int) $race->seats,
                'finalist_count' => (int) $race->finalist_count,
                'phase'          => $phase,
                'asOf'           => $asOf,
            ],
            'races' => $model->races->count() > 1
                ? $model->races
                    ->sortBy(fn (ElectionRace $r) => [$r->seat_kind, $r->district?->district_number ?? PHP_INT_MAX])
                    ->values()
                    ->map(fn (ElectionRace $r) => [
                        'id'    => (string) $r->id,
                        'label' => ElectionController::raceLabel($r),
                    ])
                    ->all()
                : [],
            'stats' => [
                'seats'               => (int) ($race?->seats ?? 0),
                'finalistPlaces'      => (int) ($race?->finalist_count ?? 0),
                'validatedCandidates' => $total,
                'myActiveApprovals'   => $myApprovals->count(),
            ],
            'standings'          => $standings->map(fn (array $row) => collect($row)->except('asOf')->all())->values()->all(),
            'standingsTruncated' => $truncated ? ['total' => $total, 'shown' => self::STANDINGS_CAP] : null,
            'myApprovals'        => $myApprovals->all(),
            'filters'            => $this->filterSources($standings),
            'approvable'         => $inFootprint && $model->status === Election::STATUS_APPROVAL_OPEN,
            'inFootprint'        => $inFootprint,
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

        return back()->with('status', 'Approved — revocable until the finalist cutoff.');
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

        return back()->with('status', 'Approval withdrawn.');
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

    /**
     * The standings list: approval_standings rows (latest day / frozen
     * snapshot) joined to their candidacies, PLUS any standing candidacies
     * validated since the last rollup, appended at the tail with zero
     * aggregate (exactly what the next rollup would report for them —
     * computed from `candidacies` alone, never from `approvals`). Rank is
     * ALWAYS the full-race rank.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function standingsFor(ElectionRace $race): Collection
    {
        $rows = $this->approvals->standings($race);

        $standingStatuses = [
            Candidacy::STATUS_VALIDATED,
            Candidacy::STATUS_IN_POOL,
            Candidacy::STATUS_FINALIST,
            Candidacy::STATUS_NON_FINALIST,
        ];

        $candidacies = Candidacy::query()
            ->with('user')
            ->where('race_id', $race->id)
            ->whereIn('status', [...$standingStatuses, Candidacy::STATUS_WITHDRAWN, Candidacy::STATUS_ELECTED, Candidacy::STATUS_DEFEATED])
            ->get()
            ->keyBy(fn (Candidacy $c) => (string) $c->id);

        $endorsements = $this->endorsementsByCandidacy($candidacies->keys()->all());
        $incumbents   = $this->incumbentUserIds($race);

        $out    = collect();
        $seen   = [];

        foreach ($rows as $standing) {
            $candidacy = $candidacies->get((string) $standing->candidacy_id);

            if ($candidacy === null) {
                continue; // candidacy soft-deleted since the rollup
            }

            $seen[] = (string) $candidacy->id;

            $out->push($this->standingRow(
                $candidacy,
                (int) $standing->rank,
                (int) $standing->approvals_count,
                (int) $standing->delta,
                $endorsements,
                $incumbents,
                $standing->as_of_date?->toDateString(),
            ));
        }

        // Tail: standing candidacies the daily rollup has not seen yet.
        $nextRank = $out->count();

        $unrolled = $candidacies
            ->filter(fn (Candidacy $c) => in_array($c->status, $standingStatuses, true)
                && ! in_array((string) $c->id, $seen, true))
            ->sortBy(fn (Candidacy $c) => [$c->validated_at?->getTimestamp() ?? PHP_INT_MAX, (string) $c->id])
            ->values();

        foreach ($unrolled as $candidacy) {
            $nextRank++;
            $out->push($this->standingRow($candidacy, $nextRank, 0, 0, $endorsements, $incumbents, null));
        }

        return $out;
    }

    /** One §B.4 standings entry (CandidateRow contract shape). */
    private function standingRow(
        Candidacy $candidacy,
        int $rank,
        int $approvals,
        int $delta,
        array $endorsements,
        array $incumbents,
        ?string $asOf,
    ): array {
        return [
            'rank'         => $rank,
            'approvals'    => $approvals,
            'delta'        => $delta,
            'asOf'         => $asOf,
            'candidacy_id' => (string) $candidacy->id,
            'status'       => $candidacy->status,
            'candidacy'    => [
                'id'            => (string) $candidacy->id,
                'name'          => $candidacy->user?->display_name ?? $candidacy->user?->name ?? 'Candidate',
                'statement'     => $candidacy->platform_statement,
                'position_tags' => $candidacy->position_tags ?? [],
                'incumbent'     => in_array((string) $candidacy->user_id, $incumbents, true),
                'profile_href'  => "/candidates/{$candidacy->id}",
                'endorsements'  => $endorsements[(string) $candidacy->id]
                    ?? ['orgs' => [], 'individual_count' => 0],
            ],
        ];
    }

    /**
     * Endorsement chips for a set of candidacies in two grouped queries
     * (org rows + individual counts) — aggregates only, never identities.
     *
     * @param  list<string>  $candidacyIds
     * @return array<string, array{orgs: list<array{id: string, name: string, type: string}>, individual_count: int}>
     */
    private function endorsementsByCandidacy(array $candidacyIds): array
    {
        if ($candidacyIds === []) {
            return [];
        }

        $byCandidacy = [];

        $orgRows = Endorsement::query()
            ->where('endorsements.is_active', true)
            ->whereNull('endorsements.withdrawn_at')
            ->where('endorsements.endorser_type', Endorsement::ENDORSER_ORGANIZATION)
            ->whereIn('endorsements.candidate_id', $candidacyIds)
            ->join('organizations as o', 'o.id', '=', 'endorsements.endorser_id')
            ->get(['endorsements.candidate_id', 'o.id', 'o.name', 'o.type']);

        foreach ($orgRows as $row) {
            $byCandidacy[(string) $row->candidate_id]['orgs'][] = [
                'id'   => (string) $row->id,
                'name' => $row->name,
                'type' => $row->type,
            ];
        }

        $individualCounts = Endorsement::query()
            ->active()
            ->where('endorser_type', Endorsement::ENDORSER_USER)
            ->whereIn('candidate_id', $candidacyIds)
            ->selectRaw('candidate_id, COUNT(*) AS n')
            ->groupBy('candidate_id')
            ->pluck('n', 'candidate_id');

        $out = [];

        foreach ($candidacyIds as $id) {
            $out[$id] = [
                'orgs'             => $byCandidacy[$id]['orgs'] ?? [],
                'individual_count' => (int) ($individualCounts[$id] ?? 0),
            ];
        }

        return $out;
    }

    /** @return list<string> user ids holding a current seat in the race's legislature */
    private function incumbentUserIds(ElectionRace $race): array
    {
        $legislatureId = $race->election?->legislature_id
            ?? Election::query()->whereKey($race->election_id)->value('legislature_id');

        if ($legislatureId === null) {
            return [];
        }

        return DB::table('legislature_members')
            ->where('legislature_id', (string) $legislatureId)
            ->whereIn('status', ['elected', 'seated'])
            ->whereNull('deleted_at')
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Client-side filter option sources (§B.4): the orgs endorsing in this
     * race + the distinct position tags on its candidacies.
     */
    private function filterSources(Collection $standings): array
    {
        $orgs = [];
        $tags = [];

        foreach ($standings as $row) {
            foreach ($row['candidacy']['endorsements']['orgs'] as $org) {
                $orgs[$org['id']] = $org;
            }
            foreach ($row['candidacy']['position_tags'] as $tag) {
                $tags[$tag] = true;
            }
        }

        ksort($tags);

        return [
            'orgs' => array_values($orgs),
            'tags' => array_keys($tags),
        ];
    }
}
