<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\ChamberVote;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\ChamberVoteService;

/**
 * F-LEG-005 — Committee Vote (R-11).
 *
 * One cast on a committee-body vote. Committee membership authorizes
 * through the narrow CommitteeRoster contract (the chamber-ops committee
 * substrate; NoopCommitteeRoster denies everything until it lands —
 * honest failure, no pretend roster). Per-kind lanes apply at committee
 * stage in bicameral chambers (q-ledger #q7).
 */
class CommitteeVoteCast implements FormHandler
{
    public function __construct(private readonly ChamberVoteService $votes)
    {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'vote.cast';
    }

    public function requiredRoles(): array
    {
        return ['R-11'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $vote = ChamberVote::query()->find($payload['vote_id'] ?? null);

        if ($vote === null) {
            throw new ConstitutionalViolation('Unknown chamber vote.', 'Art. II §2 · as implemented');
        }

        if ($vote->body_type !== ChamberVote::BODY_COMMITTEE) {
            throw new ConstitutionalViolation(
                'F-LEG-005 casts on committee votes; floor votes take F-LEG-004.',
                'Art. II §2'
            );
        }

        $member = $actor === null ? null : LegislatureMember::query()
            ->where('legislature_id', $vote->legislature_id)
            ->where('user_id', (string) $actor->getKey())
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->first();

        if ($member === null) {
            throw new ConstitutionalViolation('The filer holds no current seat in this legislature.', 'Art. II §2');
        }

        // Committee membership itself is enforced inside cast() via the
        // CommitteeRoster contract (laneForMember).
        $cast = $this->votes->cast(
            vote: $vote,
            member: $member,
            value: isset($payload['value']) ? (string) $payload['value'] : null,
            rankings: isset($payload['rankings']) && is_array($payload['rankings']) ? $payload['rankings'] : null,
            explanation: isset($payload['explanation']) ? (string) $payload['explanation'] : null,
            viaForm: 'F-LEG-005',
        );

        $vote->refresh();

        return [
            'vote_id'          => (string) $vote->id,
            'vote_type'        => $vote->vote_type,
            'committee_id'     => (string) $vote->body_id,
            'member_id'        => (string) $member->id,
            'lane'             => $cast->lane,
            'value'            => $cast->value,
            'public_rankings'  => $cast->rankings,
            'public_record_id' => (string) $cast->public_record_id,
            'vote_status'      => $vote->status,
            'vote_outcome'     => $vote->outcome,
            'jurisdiction_id'  => (string) $vote->jurisdiction_id,
        ];
    }
}
