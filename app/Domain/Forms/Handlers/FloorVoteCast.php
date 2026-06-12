<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\ChamberVote;
use App\Models\User;
use App\Services\ChamberVoteService;

/**
 * F-LEG-004 — Floor Vote (R-09).
 *
 * One cast on a whole-chamber (body_type 'legislature') vote: bill floor
 * votes (stage 'floor') and procedural motion votes (stage null) — never
 * committee-stage votes (those are F-LEG-005). Guards live in
 * ChamberVoteService::cast: vote open, current member, lane match, not
 * the Speaker (Art. II §3 — F-SPK-004 is the only speaker vote), no
 * duplicate (the cast is immutable). The cast is PUBLIC (value/rankings
 * + explanation published to public_records, Art. II §2).
 */
class FloorVoteCast implements FormHandler
{
    use ResolvesLegislativeActor;

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
        return ['R-09'];
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

        if ($vote->body_type !== ChamberVote::BODY_LEGISLATURE || $vote->stage === ChamberVote::STAGE_COMMITTEE) {
            throw new ConstitutionalViolation(
                'F-LEG-004 casts on whole-chamber votes; committee-stage votes take F-LEG-005.',
                'Art. II §2'
            );
        }

        $member = $this->currentMemberOf($actor, (string) $vote->legislature_id);

        $cast = $this->votes->cast(
            vote: $vote,
            member: $member,
            value: isset($payload['value']) ? (string) $payload['value'] : null,
            rankings: isset($payload['rankings']) && is_array($payload['rankings']) ? $payload['rankings'] : null,
            explanation: isset($payload['explanation']) ? (string) $payload['explanation'] : null,
            viaForm: 'F-LEG-004',
        );

        $vote->refresh();

        return [
            'vote_id'          => (string) $vote->id,
            'vote_type'        => $vote->vote_type,
            'member_id'        => (string) $member->id,
            'lane'             => $cast->lane,
            'value'            => $cast->value,
            // Member rankings are PUBLIC on chamber votes (Art. II §2) —
            // recorded deliberately, unlike secret ballots.
            'public_rankings'  => $cast->rankings,
            'public_record_id' => (string) $cast->public_record_id,
            'vote_status'      => $vote->status,
            'vote_outcome'     => $vote->outcome,
            'jurisdiction_id'  => (string) $vote->jurisdiction_id,
        ];
    }
}
