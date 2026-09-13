<?php

namespace App\Http\Presenters;

use App\Domain\Forms\Support\RaceFootprint;
use App\Http\Controllers\Elections\CandidacyController;
use App\Http\Controllers\Elections\ElectionController;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\ApprovalStanding;
use App\Models\Endorsement;
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
 * manage/withdraw when the viewer IS the candidate. Endorsement directories
 * are independent partial props assembled by CandidacyEndorsementDirectory.
 * Writes still go to the existing F-CAN-001/002/003 endpoints —
 * this class only reads.
 *
 * Pseudonymity (Art. I): every person named here resolves through
 * displayName() — users.display_name (the F-IND-002 choice), else the
 * public social pseudonym, else a stable Resident-hash. NEVER users.name.
 */
class CandidacyPanel
{
    public function __construct(private readonly ApprovalService $approvals) {}

    /**
     * The full panel for one candidacy, viewer-aware.
     *
     * @return array{candidacy: array, standing: ?array, machine: list<string>, currentState: string,
     *               isOwner: bool, can: array, organizations: list<array>}
     */
    public function for(Candidacy $model, ?User $viewer): array
    {
        $model->loadMissing(['user', 'election.jurisdiction', 'race.jurisdiction', 'race.district']);

        $election = $model->election;
        $race = $model->race;
        $phase = ElectionController::phase($election->status);
        $isOwner = $viewer !== null && (string) $viewer->getKey() === (string) $model->user_id;

        ['machine' => $machine, 'current' => $current] = CandidacyController::machineFor($model->status);

        // EO-5 — the viewer's own endorsement of this candidacy and whether
        // they may endorse or withdraw now. The individual endorsement is the
        // endorser's public choice; it is neither the secret approval vote nor
        // the organization handshake. There is no election-phase window
        // (operator ruling 2026-09-13): eligibility mirrors the handler's
        // standing + footprint gates only, in any election status. Never
        // computed for a signed-out viewer.
        $viewerEndorsement = $this->viewerEndorsementFor($model, $viewer);
        $endorseEligible = $viewer !== null
            && ! $isOwner
            && $race !== null
            && in_array($model->status, [
                Candidacy::STATUS_REGISTERED,
                Candidacy::STATUS_VALIDATED,
                Candidacy::STATUS_IN_POOL,
                Candidacy::STATUS_FINALIST,
            ], true)
            && RaceFootprint::bestRaceForUser(
                (string) $viewer->getKey(),
                (string) $election->id,
                (string) $race->id,
            ) !== null;
        $endorsesNow = $viewerEndorsement !== null && ! $viewerEndorsement['withdrawn'];

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
                // EO-5: endorse when eligible and not currently endorsing;
                // withdraw when eligible and currently endorsing. The engine
                // (F-IND-025/026) is the boundary — these only shape the UI.
                'endorse' => $endorseEligible && ! $endorsesNow,
                'withdraw_endorsement' => $endorseEligible && $endorsesNow,
            ],
            'viewerEndorsement' => $viewerEndorsement,
            'organizations' => $isOwner
                ? Organization::query()
                    ->where('is_active', true)
                    ->orderByRaw(DB::getDriverName() === 'pgsql' ? 'lower(name) COLLATE "C"' : 'lower(name)')->orderBy('id')
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
     * public pseudonym (social_profiles display_name / @handle), else a stable
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

        $profile = SocialProfile::query()->where('user_id', (string) $user->getKey())
            ->where('visibility', SocialProfile::VISIBILITY_PUBLIC)->first();

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
            ->where('visibility', SocialProfile::VISIBILITY_PUBLIC)
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
        $date = $this->approvals->standingsDate($race);
        if ($date === null) return null;
        $snapshot = ApprovalStanding::query()->where('race_id', $race->id)->where('as_of_date', $date);
        $mine = (clone $snapshot)->where('candidacy_id', $candidacy->id)->first();
        if ($mine === null) return null;
        $total = (clone $snapshot)->count();
        $line = (int) min($race->finalist_count, $total);
        $lineApprovals = (clone $snapshot)->where('rank', $line)->value('approvals_count');
        $topApprovals = (clone $snapshot)->where('rank', 1)->value('approvals_count');

        $isFinalist = $phase === 'approval'
            ? (int) $mine->rank <= (int) $race->finalist_count
            : in_array($candidacy->status, [Candidacy::STATUS_FINALIST, Candidacy::STATUS_ELECTED, Candidacy::STATUS_DEFEATED], true);

        return [
            'rank' => (int) $mine->rank,
            'of' => $total,
            'approvals' => (int) $mine->approvals_count,
            'isFinalist' => $isFinalist,
            'lineApprovals' => (int) ($lineApprovals ?? 0),
            'topApprovals' => (int) ($topApprovals ?? $mine->approvals_count),
            'frozen' => (bool) $mine->is_frozen,
            'asOf' => $mine->as_of_date?->toDateString(),
        ];
    }

    /**
     * The viewer's own endorsement of this candidacy, or null. One bounded
     * single-row lookup that respects canonical precedence over the legacy
     * spelling (same shape as CandidacyEndorsementDirectory::hasPublicEdge).
     * Scoped to the viewer's own id — it never reads another endorser's row.
     *
     * @return array{is_public: bool, withdrawn: bool, endorsed_at: ?string}|null
     */
    private function viewerEndorsementFor(Candidacy $candidacy, ?User $viewer): ?array
    {
        if ($viewer === null) {
            return null;
        }

        $query = Endorsement::query()->where('election_id', $candidacy->election_id)
            ->where('candidate_id', $candidacy->id)->where('endorser_id', (string) $viewer->getKey());
        $columns = ['is_public', 'withdrawn_at', 'endorsed_at'];
        $edge = (clone $query)->where('endorser_type', Endorsement::ENDORSER_USER)->first($columns)
            ?? (clone $query)->where('endorser_type', 'users')->first($columns);

        if ($edge === null) {
            return null;
        }

        return [
            'is_public'   => (bool) $edge->is_public,
            'withdrawn'   => $edge->withdrawn_at !== null,
            'endorsed_at' => $edge->endorsed_at?->toIso8601String(),
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
