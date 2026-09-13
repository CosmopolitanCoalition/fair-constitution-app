<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Candidacy;
use App\Models\ClockTimer;
use App\Models\Election;
use App\Models\ElectionAudit;
use App\Models\ElectionCertification;
use App\Models\ElectionRace;
use App\Models\ExecutiveMember;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\RaceResult;
use App\Models\Tabulation;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A corrected count updates its existing general-election term, never starts a cycle. */
class ElectionCertificationReconciliationService
{
    public function __construct(
        private readonly VacancyService $vacancies,
        private readonly ClockService $clocks,
        private readonly RoleService $roles,
        private readonly AuditService $audit,
    ) {}

    public function reconcile(Election $election, ElectionCertification $certification, ElectionCertification $previous, array $recordHashes, ?User $actor): array
    {
        $chamber = Legislature::query()->whereKey($election->legislature_id)->lockForUpdate()->firstOrFail();
        $original = Term::query()->where('legislature_id', $chamber->id)
            ->where('source_election_id', $election->id)->where('term_class', Term::CLASS_LOCKSTEP)
            ->orderBy('starts_on')->orderBy('id')->first();
        if ($original === null || $chamber->term_starts_on?->toDateString() !== $original->starts_on?->toDateString()
            || $chamber->term_ends_on?->toDateString() !== $original->ends_on?->toDateString()) {
            $this->refuse('This audit belongs to a different or unresolvable chamber term. Correcting its historical record cannot replace the current chamber.');
        }
        if ($previous->count_record_hash === $certification->count_record_hash) {
            $this->refuse('This count is already certified; a new corrected audit record is required.');
        }

        $races = $election->races()->orderBy('id')->get();
        $counts = [];
        foreach ($races as $race) {
            $counts[$race->id] = Tabulation::query()->where('race_id', $race->id)
                ->where('status', Tabulation::STATUS_COMPLETE)->where('record_hash', $recordHashes[$race->id])
                ->orderByDesc('completed_at')->firstOrFail();
        }
        $order = ElectionAudit::query()->where('election_id', $election->id)
            ->where('outcome', ElectionAudit::OUTCOME_CORRECTED)->whereNotNull('resolved_at')
            ->where('ordered_at', '>=', $previous->certified_at)
            ->whereIn('tabulation_id', array_map(fn ($count) => $count->id, $counts))
            ->orderByDesc('ordered_at')->first();
        if ($order === null) {
            $this->refuse('No newly resolved corrected audit covers this superseding count.');
        }

        // Verify the exact prior certification snapshot before comparing people.
        // Countbacks are separate vacancy history, never the general count.
        $priorHashes = [];
        $plans = [];
        $current = LegislatureMember::query()->where('legislature_id', $chamber->id)->current()->lockForUpdate()->get();
        foreach ($races as $race) {
            $prior = Tabulation::query()->where('race_id', $race->id)
                ->whereIn('status', [Tabulation::STATUS_COMPLETE, Tabulation::STATUS_SUPERSEDED])
                ->whereIn('kind', [Tabulation::KIND_INITIAL, Tabulation::KIND_AUDIT_RERUN])
                ->whereNotNull('record_hash')->where('completed_at', '<', $order->ordered_at)
                ->orderByDesc('completed_at')->first();
            if ($prior === null) {
                $this->refuse('The prior certified race record cannot be resolved safely.');
            }
            $priorHashes[$race->id] = $prior->record_hash;
            $before = $this->winners($prior, $race);
            $after = $this->winners($counts[$race->id], $race);
            $lost = array_diff(array_keys($before), array_keys($after));
            $added = array_diff(array_keys($after), array_keys($before));
            $members = $current->where('election_id', $election->id)->where('elected_in_race_id', $race->id)->keyBy('user_id');
            $displaced = [];
            if ($lost !== [] || $added !== []) {
                $independent = LegislatureMember::query()->where('legislature_id', $chamber->id)
                    ->where('election_id', $election->id)->where('elected_in_race_id', $race->id)
                    ->whereIn('status', [LegislatureMember::STATUS_VACATED, LegislatureMember::STATUS_REMOVED])
                    ->where(fn ($q) => $q->whereNull('vacancy_reason')->orWhere('vacancy_reason', '!=', 'audit_correction'))->exists();
                if ($independent || array_diff(array_keys($before), $members->keys()->all()) !== []
                    || array_diff($members->keys()->all(), array_keys($before)) !== []) {
                    $this->refuse('This changed-winner race has independent vacancy or replacement history. Reconcile that history before seating the corrected result; no existing office has been changed.');
                }
                if (count($lost) !== count($added)) {
                    $this->refuse('The corrected count changes the occupied-seat total; an explicit seat reconciliation is required.');
                }
                if ($certification->certified_at->startOfDay()->greaterThan($original->ends_on)) {
                    $this->refuse('The original term has expired; a corrected result cannot create a replacement beyond that expiry.');
                }
                foreach ($added as $userId) {
                    if ($current->contains('user_id', $userId)) {
                        $this->refuse('A corrected winner already holds another current seat in this chamber.');
                    }
                }
                foreach ($lost as $userId) {
                    $member = $members[$userId];
                    $term = $member->term;
                    if ($term === null || $term->status !== Term::STATUS_ACTIVE || ! $term->ends_on->isSameDay($original->ends_on)) {
                        $this->refuse('The displaced seat does not have the original active term expiry.');
                    }
                    $displaced[] = $member;
                }
                usort($displaced, fn ($a, $b) => [$a->seat_no, $a->id] <=> [$b->seat_no, $b->id]);
            }
            $plans[] = compact('race', 'before', 'after', 'members', 'displaced', 'added');
        }
        ksort($priorHashes);
        $priorHash = hash('sha256', implode("\n", array_map(fn ($id) => $id.':'.$priorHashes[$id], array_keys($priorHashes))));
        if (! hash_equals($previous->count_record_hash, $priorHash)) {
            $this->refuse('The recovered prior race records do not match the certification being superseded.');
        }
        $successors = Election::query()->where('prior_election_id', $election->id)->where('kind', Election::KIND_GENERAL)->limit(2)->get(['id']);
        if ($successors->count() !== 1) {
            $this->refuse('The original successor election is missing or ambiguous; no new cycle will be created by an audit.');
        }

        $winners = $terms = $displacedIds = $newIds = $preservedIds = $vacancyIds = $delegatedIds = [];
        foreach ($plans as $plan) {
            $members = $plan['members'];
            $incoming = array_values(array_intersect_key($plan['after'], array_flip($plan['added'])));
            foreach ($plan['displaced'] as $index => $member) {
                $vacancy = $this->vacancies->declare($member, 'audit_correction', $actor, 'F-ELB-004', queueCountback: false);
                foreach (ClockTimer::armed()->where('clock_id', 'CLK-10')->where('subject_type', 'term')->where('subject_id', $member->term_id)->get() as $timer) {
                    $this->clocks->cancel($timer, 'seat displaced by corrected election count');
                }
                // Ex-officio authority ends only for the displaced legislative seat.
                $delegates = ExecutiveMember::query()->where('legislature_member_id', $member->id)
                    ->where('selection', ExecutiveMember::SELECTION_DELEGATED_PROPORTIONAL)->seated()->get();
                foreach ($delegates as $delegate) {
                    $delegate->forceFill(['status' => ExecutiveMember::STATUS_LEFT, 'left_at' => now()->toDateString()])->save();
                    $delegatedIds[] = (string) $delegate->id;
                }
                $replacement = $this->seatReplacement($election, $certification, $plan['race'], $incoming[$index], $member, $original);
                $vacancy->forceFill(['status' => 'filled', 'filled_by_user_id' => $replacement->user_id, 'filled_at' => now()])->save();
                $members->forget($member->user_id);
                $members->put($replacement->user_id, $replacement);
                $displacedIds[] = (string) $member->id;
                $newIds[] = (string) $replacement->id;
                $vacancyIds[] = (string) $vacancy->id;
            }
            foreach ($plan['after'] as $userId => $winner) {
                $member = $members->get($userId);
                if ($member !== null) {
                    $member->forceFill(['vote_share_norm' => $winner['result']->vote_share_norm])->save();
                    if (! in_array((string) $member->id, $newIds, true)) {
                        $preservedIds[] = (string) $member->id;
                    }
                    $term = $member->term;
                    $terms[] = ['term_id' => $member->term_id, 'holder_user_id' => $userId, 'starts_on' => $term?->starts_on?->toDateString(), 'ends_on' => $term?->ends_on?->toDateString(), 'term_class' => Term::CLASS_LOCKSTEP];
                }
                $winners[] = ['user_id' => $userId, 'candidacy_id' => (string) $winner['candidacy']->id, 'race_id' => (string) $plan['race']->id, 'seat_no' => $member?->seat_no, 'member_id' => $member?->id, 'vote_share_norm' => $winner['result']->vote_share_norm];
                // Independent resignations/replacements remain historical facts.
                if ($member !== null) {
                    $winner['candidacy']->forceFill(['status' => Candidacy::STATUS_ELECTED])->save();
                }
            }
            foreach (array_diff_key($plan['before'], $plan['after']) as $loser) {
                $loser['candidacy']->forceFill(['status' => Candidacy::STATUS_DEFEATED])->save();
            }
        }
        $this->roles->flush();
        $extra = [
            'correction' => true, 'audit_id' => (string) $order->id,
            'preserved_member_ids' => $preservedIds, 'displaced_member_ids' => $displacedIds,
            'new_member_ids' => $newIds, 'filled_vacancy_ids' => $vacancyIds, 'retired_delegated_member_ids' => $delegatedIds,
            'winners' => $winners, 'terms' => $terms,
            'term_window' => ['starts_on' => $original->starts_on->toDateString(), 'ends_on' => $original->ends_on->toDateString(), 'inherited' => true],
            'legislature' => ['id' => (string) $chamber->id, 'status' => $chamber->status, 'term_number' => (int) $chamber->term_number],
            'next_election_id' => (string) $successors->sole()->id,
        ];
        $this->audit->append(module: 'elections', event: 'election.corrected_seating_reconciled', payload: $extra, ref: 'F-ELB-004', jurisdictionId: $election->jurisdiction_id);

        return $extra;
    }

    private function winners(Tabulation $count, ElectionRace $race): array
    {
        $winners = [];
        foreach (RaceResult::query()->where('tabulation_id', $count->id)->whereNotNull('seat_no')->orderBy('seat_no')->get() as $result) {
            $candidate = Candidacy::query()->whereKey($result->candidacy_id)->where('race_id', $race->id)->firstOrFail();
            if (isset($winners[$candidate->user_id])) {
                $this->refuse('A corrected count contains duplicate winner identities.');
            }
            $winners[(string) $candidate->user_id] = ['candidacy' => $candidate, 'result' => $result];
        }

        return $winners;
    }

    private function seatReplacement(Election $election, ElectionCertification $certification, ElectionRace $race, array $winner, LegislatureMember $displaced, Term $original): LegislatureMember
    {
        $window = CertificationService::inheritedWindow(CarbonImmutable::parse($certification->certified_at), $original->ends_on);
        $memberId = (string) Str::uuid();
        $term = Term::create([
            'office_kind' => 'legislature_seat', 'office_type' => 'legislature_members', 'office_id' => $memberId,
            'holder_user_id' => $winner['candidacy']->user_id, 'jurisdiction_id' => $election->jurisdiction_id,
            'legislature_id' => $election->legislature_id, 'term_class' => Term::CLASS_LOCKSTEP,
            'starts_on' => $window['starts_on']->toDateString(), 'ends_on' => $window['ends_on']->toDateString(),
            'source_election_id' => $election->id, 'status' => Term::STATUS_ACTIVE,
        ]);
        $home = DB::table('residency_confirmations')->where('user_id', $winner['candidacy']->user_id)->where('is_active', true)->orderByRaw('depth ASC NULLS LAST')->value('jurisdiction_id');
        $member = LegislatureMember::create([
            'id' => $memberId, 'legislature_id' => $election->legislature_id, 'user_id' => $winner['candidacy']->user_id,
            'seat_type' => $displaced->seat_type, 'seat_no' => $displaced->seat_no, 'district_id' => $race->district_id,
            'elected_in_race_id' => $race->id, 'election_id' => $election->id, 'vote_share_norm' => $winner['result']->vote_share_norm,
            'status' => LegislatureMember::STATUS_ELECTED, 'is_speaker' => false, 'term_id' => $term->id,
            'seated_on' => $window['starts_on']->toDateString(), 'term_ends_on' => $window['ends_on']->toDateString(), 'home_jurisdiction_id' => $home,
        ]);
        $this->clocks->arm('CLK-10', $election->jurisdiction_id, 'term', $term->id, null, ['step' => 'lockstep', 'ends_on' => $window['ends_on']->toDateString()]);

        return $member;
    }

    private function refuse(string $message): never
    {
        throw new ConstitutionalViolation($message, 'F-ELB-004 · Art. II §5 · CLK-10');
    }
}
