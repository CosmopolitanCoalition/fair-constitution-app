<?php

namespace App\Http\Presenters;

use App\Models\ChamberVote;
use App\Models\ChamberVoteTally;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\SessionAttendance;
use App\Models\VoteCast;
use Illuminate\Support\Collection;

/**
 * ChamberVotePresenter — maps one chamber_votes row (+ lanes + casts) to
 * the Legislature/VoteTally + Legislature/VoteCastList prop contracts
 * (PHASE_C_DESIGN_frontend.md §C / §A.7).
 *
 * PURE READER of engine snapshots: every number it emits (serving /
 * required_yes / quorum_required, per lane) comes off chamber_vote_tallies
 * — it NEVER calls quorum()/supermajority(). If the UI and the engine
 * ever disagree, the audit chain shows the engine.
 */
class ChamberVotePresenter
{
    public const KIND_LABELS = [
        ChamberVoteTally::LANE_TYPE_A => 'Type A · population-apportioned',
        ChamberVoteTally::LANE_TYPE_B => 'Type B · one per constituent',
    ];

    /**
     * VoteTally props for a vote (unicameral or bicameral, any stage).
     *
     * @return array<string, mixed>
     */
    public function tallyProps(ChamberVote $vote): array
    {
        $vote->loadMissing('tallies');

        $thresholdClass = $this->thresholdClass($vote);

        $base = [
            'mode'            => $vote->bicameral ? 'bicameral' : 'unicameral',
            'stage'           => $vote->stage ?? 'floor',
            'thresholdClass'  => $thresholdClass,
            'outcome'         => $this->outcome($vote),
            'speakerTiebreak' => (bool) $vote->speaker_tiebreak,
            'vote_id'         => (string) $vote->id,
            'vote_type'       => $vote->vote_type,
            'status'          => $vote->status,
        ];

        if (! $vote->bicameral) {
            $tally = $vote->tallies->firstWhere('lane', ChamberVoteTally::LANE_ALL)
                ?? $vote->tallies->first();

            $hasCasts = ($tally?->yes ?? 0) + ($tally?->no ?? 0) + ($tally?->abstain ?? 0) > 0
                || $vote->status === ChamberVote::STATUS_CLOSED;

            return $base + [
                'serving'     => (int) ($tally?->serving ?? $vote->serving_snapshot),
                'requiredYes' => (int) ($tally?->required_yes ?? 0),
                'tallies'     => $hasCasts && $tally !== null
                    ? ['yes' => (int) $tally->yes, 'no' => (int) $tally->no, 'abstain' => (int) $tally->abstain]
                    : null,
                'quorum'      => $tally !== null ? [
                    'present'  => $this->presence($vote, $tally),
                    'required' => (int) $tally->quorum_required,
                ] : null,
                'kinds'       => null,
            ];
        }

        $kinds = $vote->tallies
            ->sortBy('lane') // type_a before type_b
            ->values()
            ->map(fn (ChamberVoteTally $tally) => [
                'kind'        => $tally->lane,
                'label'       => self::KIND_LABELS[$tally->lane] ?? $tally->lane,
                'serving'     => (int) $tally->serving,
                'requiredYes' => (int) $tally->required_yes,
                'yes'         => (int) $tally->yes,
                'no'          => (int) $tally->no,
                'abstain'     => (int) $tally->abstain,
                'quorum'      => [
                    'present'  => $this->presence($vote, $tally),
                    'required' => (int) $tally->quorum_required,
                ],
                // Agreement is the CLOSED lane's stored verdict — null while open.
                'agreed'      => $tally->passed,
            ])
            ->all();

        return $base + [
            'serving'     => (int) $vote->serving_snapshot,
            'requiredYes' => null,
            'tallies'     => null,
            'quorum'      => null,
            'kinds'       => $kinds,
        ];
    }

    /**
     * VoteCastList rows for a yes/no vote: every cast, member-named and
     * public (Art. II §2); once the vote is decided, members who never
     * cast render as 'absent' — counts the same as a no. The Speaker is
     * excluded from the absent fill (they structurally cannot cast except
     * F-SPK-004, and a tie-break cast lists itself).
     *
     * @return list<array{member_name: string, seat_kind: ?string, value: string, explanation: ?string, speaker_tiebreak: bool}>
     */
    public function casts(ChamberVote $vote): array
    {
        if ($vote->vote_method !== ChamberVote::METHOD_YES_NO) {
            return [];
        }

        $casts = VoteCast::query()
            ->where('vote_id', $vote->id)
            ->with('member.user:id,name,display_name')
            ->orderBy('cast_at')
            ->get();

        $rows = $casts->map(fn (VoteCast $cast) => [
            'member_name'      => $this->memberName($cast->member),
            'seat_kind'        => $cast->member?->seatKind(),
            'value'            => (string) $cast->value,
            'explanation'      => $cast->explanation,
            'speaker_tiebreak' => (bool) $cast->is_tiebreak,
        ]);

        if ($vote->status === ChamberVote::STATUS_CLOSED
            && $vote->body_type === ChamberVote::BODY_LEGISLATURE) {
            $castMemberIds = $casts->pluck('member_id')->map(fn ($id) => (string) $id)->all();

            $speakerId = $vote->legislature_id !== null
                ? Legislature::query()->whereKey($vote->legislature_id)->value('speaker_id')
                : null;

            $absent = LegislatureMember::query()
                ->where('legislature_id', $vote->legislature_id)
                ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
                ->whereNotIn('id', $castMemberIds)
                ->when($speakerId !== null, fn ($q) => $q->whereKeyNot($speakerId))
                ->with('user:id,name,display_name')
                ->orderBy('seat_no')
                ->get()
                ->map(fn (LegislatureMember $member) => [
                    'member_name'      => $this->memberName($member),
                    'seat_kind'        => $member->seatKind(),
                    'value'            => 'absent',
                    'explanation'      => null,
                    'speaker_tiebreak' => false,
                ]);

            $rows = $rows->concat($absent);
        }

        return $rows->values()->all();
    }

    /**
     * RCV round record for display (speaker/chair ballots) straight off
     * chamber_votes.rcv_record — counted by the PROTECTED
     * VoteCountingService, rendered verbatim.
     */
    public function rcvRounds(ChamberVote $vote): ?array
    {
        if ($vote->rcv_record === null) {
            return null;
        }

        $names = $this->memberNames(collect($vote->rcv_record['rounds'] ?? [])
            ->flatMap(fn (array $round) => array_keys($round['tallies']['candidates'] ?? []))
            ->push(...array_filter([
                $vote->rcv_record['winner_member_id'] ?? null,
            ]))
            ->unique()
            ->values());

        $rounds = collect($vote->rcv_record['rounds'] ?? [])->map(fn (array $round, int $i) => [
            'round'   => (int) ($round['round_no'] ?? $i + 1),
            'action'  => (string) ($round['action'] ?? ''),
            'subject' => isset($round['candidacy_id'])
                ? ($names[(string) $round['candidacy_id']] ?? (string) $round['candidacy_id'])
                : null,
            // Standing support only (zero-tally candidates omitted for legibility).
            'tallies' => collect($round['tallies']['candidates'] ?? [])
                ->filter(fn ($micro) => (int) $micro > 0)
                ->map(fn ($micro, $memberId) => [
                    'member_id' => (string) $memberId,
                    'name'      => $names[(string) $memberId] ?? (string) $memberId,
                    'votes'     => round(((int) $micro) / \App\Services\VoteCountingService::SCALE, 2),
                ])
                ->sortByDesc('votes')
                ->values()
                ->all(),
        ])->all();

        $winnerId = $vote->rcv_record['winner_member_id'] ?? null;

        return [
            'rounds' => $rounds,
            'winner' => $winnerId !== null
                ? ($names[(string) $winnerId] ?? (string) $winnerId)
                : null,
        ];
    }

    // -------------------------------------------------------------------------

    /** @return array<string, string> member id → display name */
    private function memberNames(Collection $memberIds): array
    {
        if ($memberIds->isEmpty()) {
            return [];
        }

        return LegislatureMember::query()
            ->whereIn('id', $memberIds)
            ->with('user:id,name,display_name')
            ->get()
            ->mapWithKeys(fn (LegislatureMember $m) => [(string) $m->id => $this->memberName($m)])
            ->all();
    }

    private function memberName(?LegislatureMember $member): string
    {
        return $member?->user?->display_name ?: ($member?->user?->name ?? 'Unknown member');
    }

    /**
     * Quorum-presence display: the CLOSED lane's stored present; open
     * floor votes in a session read live attendance; anything else =
     * casts so far. Presence feeds ONLY the quorum meter — never an
     * outcome.
     */
    private function presence(ChamberVote $vote, ChamberVoteTally $tally): int
    {
        if ($tally->present !== null) {
            return (int) $tally->present;
        }

        if ($vote->held_in_session_id !== null && $vote->body_type === ChamberVote::BODY_LEGISLATURE) {
            return SessionAttendance::query()
                ->where('session_id', $vote->held_in_session_id)
                ->whereIn('status', SessionAttendance::COUNTED_PRESENT)
                ->whereIn('member_id', function ($sub) use ($vote, $tally) {
                    $sub->select('id')
                        ->from('legislature_members')
                        ->where('legislature_id', $vote->legislature_id)
                        ->whereIn('status', LegislatureMember::CURRENT_STATUSES);

                    if ($tally->lane !== ChamberVoteTally::LANE_ALL) {
                        $sub->where('seat_type', $tally->lane === ChamberVoteTally::LANE_TYPE_A ? 'a' : 'b');
                    }
                })
                ->count();
        }

        return VoteCast::query()->where('vote_id', $vote->id)->where('lane', $tally->lane)->count();
    }

    private function thresholdClass(ChamberVote $vote): string
    {
        if ($vote->vote_method === ChamberVote::METHOD_RCV) {
            return 'rcv';
        }

        if ($vote->bicameral) {
            return $vote->threshold_basis === ChamberVote::BASIS_SUPERMAJORITY
                ? 'bicameral_supermajority'
                : 'bicameral_majority';
        }

        if ($vote->body_type === ChamberVote::BODY_COMMITTEE) {
            return 'committee_majority';
        }

        return $vote->threshold_basis;
    }

    private function outcome(ChamberVote $vote): string
    {
        if ($vote->status === ChamberVote::STATUS_OPEN) {
            return 'pending';
        }

        return match (true) {
            $vote->outcome === ChamberVote::OUTCOME_ADOPTED && (bool) $vote->speaker_tiebreak => 'tied_broken',
            $vote->outcome === ChamberVote::OUTCOME_ADOPTED => 'adopted',
            $vote->outcome === ChamberVote::OUTCOME_TIED    => 'tied',
            default                                         => 'failed',
        };
    }
}
