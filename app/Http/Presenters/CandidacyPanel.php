<?php

namespace App\Http\Presenters;

use App\Http\Controllers\Elections\CandidacyController;
use App\Http\Controllers\Elections\ElectionController;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\Endorsement;
use App\Models\EndorsementRequest;
use App\Models\ElectionRace;
use App\Models\Organization;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Support\Facades\DB;

/**
 * The Candidacy tab of the ONE person profile (v3.2 ruling 0a: a candidate
 * page is not a separate identity — electoral/candidate-profile is a
 * redirect, this panel is what it lands on).
 *
 * Ported from CandidacyController::show()'s private assembly when that
 * route became a redirect (Wave 2, lane 15). Same data contract the old
 * Elections/CandidateProfile page carried: statement + tags, the approval
 * standing (daily aggregate, never a live count), the ESM-06 stage strip,
 * the endorsement web, requests + manage/withdraw when the viewer IS the
 * candidate. Writes still go to the existing F-CAN-001/002/003 endpoints —
 * this class only reads.
 *
 * Pseudonymity (Art. I): every person named here resolves through
 * displayName() — users.display_name (the F-IND-002 choice), else the
 * social pseudonym, else a stable Resident-hash. NEVER users.name.
 */
class CandidacyPanel
{
    public function __construct(private readonly ApprovalService $approvals)
    {
    }

    /**
     * The full panel for one candidacy, viewer-aware.
     *
     * @return array{candidacy: array, standing: ?array, machine: list<string>, currentState: string,
     *               endorsements: array, requests: list<array>, isOwner: bool, can: array, organizations: list<array>}
     */
    public function for(Candidacy $model, ?User $viewer): array
    {
        $model->loadMissing(['user', 'election.jurisdiction', 'race.jurisdiction', 'race.district']);

        $election = $model->election;
        $race = $model->race;
        $phase = ElectionController::phase($election->status);
        $isOwner = $viewer !== null && (string) $viewer->getKey() === (string) $model->user_id;

        ['machine' => $machine, 'current' => $current] = CandidacyController::machineFor($model->status);

        return [
            'candidacy' => [
                'id' => (string) $model->id,
                'name' => self::displayName($model->user),
                'statement' => $model->platform_statement,
                'position_tags' => $model->position_tags ?? [],
                'status' => $model->status,
                'withdrawn' => $model->status === Candidacy::STATUS_WITHDRAWN,
                'incumbent' => $this->isIncumbent($model),
                'race' => $race === null ? null : [
                    'id' => (string) $race->id,
                    'election_id' => (string) $election->id,
                    'label' => ElectionController::raceLabel($race),
                    'seats' => (int) $race->seats,
                    'finalist_count' => (int) $race->finalist_count,
                    'phase' => $phase,
                ],
            ],
            'standing' => $race === null ? null : $this->standingFor($model, $race, $phase),
            'machine' => $machine,
            'currentState' => $current,
            'endorsements' => $this->endorsementsFor($model),
            'requests' => $isOwner ? $this->requestsFor($model) : [],
            'isOwner' => $isOwner,
            'can' => [
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
        ];
    }

    /**
     * Art. I display resolution — the one rule for every name on the person
     * profile: the F-IND-002 chosen display name, else the social-plane
     * pseudonym (social_profiles display_name / @handle), else a stable
     * non-PII hash. users.name (legal) is structurally unreachable here.
     */
    public static function displayName(?User $user): string
    {
        if ($user === null) {
            return 'Candidate';
        }

        if (! empty($user->display_name)) {
            return (string) $user->display_name;
        }

        $profile = SocialProfile::query()->where('user_id', (string) $user->getKey())->first();

        if (! empty($profile?->display_name)) {
            return (string) $profile->display_name;
        }

        if (! empty($profile?->handle)) {
            return '@'.$profile->handle;
        }

        return 'Resident-'.substr(hash('sha256', (string) $user->getKey()), 0, 8);
    }

    /** Bulk variant of displayName() — one social_profiles query for a set of user ids. */
    public static function displayNames(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('strval', $userIds)));

        if ($userIds === []) {
            return [];
        }

        $users = User::query()->whereIn('id', $userIds)->get(['id', 'display_name'])->keyBy('id');
        $profiles = SocialProfile::query()->whereIn('user_id', $userIds)
            ->get(['user_id', 'display_name', 'handle'])->keyBy('user_id');

        $out = [];

        foreach ($userIds as $id) {
            $chosen = $users[$id]->display_name ?? null;
            $social = $profiles[$id]->display_name ?? null;
            $handle = $profiles[$id]->handle ?? null;

            $out[$id] = ! empty($chosen) ? (string) $chosen
                : (! empty($social) ? (string) $social
                : (! empty($handle) ? '@'.$handle
                : 'Resident-'.substr(hash('sha256', $id), 0, 8)));
        }

        return $out;
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
            'rank' => (int) $mine->rank,
            'of' => $standings->count(),
            'approvals' => (int) $mine->approvals_count,
            'isFinalist' => $isFinalist,
            'lineApprovals' => (int) ($lineRow?->approvals_count ?? 0),
            'topApprovals' => (int) ($standings->firstWhere('rank', 1)?->approvals_count ?? $mine->approvals_count),
            'frozen' => (bool) $mine->is_frozen,
            'asOf' => $mine->as_of_date?->toDateString(),
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
        // public endorsements in this election. Names resolve through the
        // pseudonym chain — the legal-name fallback the old page carried
        // does not exist on the person profile.
        $publicWeb = [];

        if ($publicIds->isNotEmpty()) {
            $webRows = Endorsement::query()
                ->where('endorsements.is_active', true)
                ->whereNull('endorsements.withdrawn_at')
                ->where('endorsements.is_public', true)
                ->where('endorsements.election_id', $candidacy->election_id)
                ->where('endorsements.endorser_type', Endorsement::ENDORSER_USER)
                ->whereIn('endorsements.endorser_id', $publicIds)
                ->join('candidacies as c', 'c.id', '=', 'endorsements.candidate_id')
                ->get(['endorsements.endorser_id', 'c.id as candidacy_id', 'c.user_id as candidate_user_id']);

            $candidateUserIds = Candidacy::query()
                ->where('election_id', $candidacy->election_id)
                ->pluck('user_id')
                ->map(fn ($id) => (string) $id);

            $names = self::displayNames([
                ...$publicIds->all(),
                ...$webRows->pluck('candidate_user_id')->map(fn ($id) => (string) $id)->all(),
            ]);

            $publicWeb = $publicIds->map(fn (string $endorserId) => [
                'name' => $names[$endorserId],
                'user_id' => $endorserId,
                'alsoCandidate' => $candidateUserIds->contains($endorserId),
                'endorses' => $webRows
                    ->filter(fn ($row) => (string) $row->endorser_id === $endorserId)
                    ->map(fn ($row) => [
                        'candidacy_id' => (string) $row->candidacy_id,
                        'user_id' => (string) $row->candidate_user_id,
                        'name' => $names[(string) $row->candidate_user_id],
                    ])
                    ->values()
                    ->all(),
            ])->values()->all();
        }

        return [
            'orgs' => $orgRows->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => $row->name,
                'type' => $row->type,
                'granted_at' => $row->endorsed_at,
            ])->all(),
            'individual' => [
                'total' => $individuals->count(),
                'public' => $publicIds->count(),
                'private' => $individuals->count() - $publicIds->count(),
            ],
            'publicWeb' => $publicWeb,
        ];
    }

    /** @return list<array{org_name: ?string, requested_at: ?string, status: string}> */
    private function requestsFor(Candidacy $candidacy): array
    {
        return EndorsementRequest::query()
            ->with('organization')
            ->where('candidacy_id', $candidacy->id)
            ->orderByDesc('requested_at')
            ->get()
            ->map(fn (EndorsementRequest $r) => [
                'org_name' => $r->organization?->name,
                'requested_at' => $r->requested_at?->toIso8601String(),
                'status' => $r->status,
            ])
            ->all();
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
