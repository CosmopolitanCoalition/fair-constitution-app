<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\User;
use App\Services\Legislature\SpeakerService;

/**
 * F-LEG-008 — Speaker Nomination/Election Vote (chamber ops §B.1).
 *
 * SUPERMAJORITY RCV: countRcv rounds, win condition = the snapshotted
 * ConstitutionalValidator::supermajority(serving) — non-casters and
 * exhausted ballots stay in the denominator. Candidates = serving members
 * (any member is nominable; neutrality is a duty of the office, not an
 * eligibility test). All serving members cast, the incumbent Speaker
 * included (constitutive election of the body — Art. II §3 · as
 * implemented).
 *
 * Two modes:
 *  - SYSTEM filing `{legislature_id, action: 'open'}` — opens the
 *    balloting (first session has no R-10; replace_speaker motion
 *    adoption also opens one through SpeakerService).
 *  - MEMBER filing `{legislature_id, rankings: [member ids…]}` — appends
 *    the member's public cast; the engine auto-closes at full
 *    participation and a supermajority winner is seated in the same
 *    transaction. No supermajority → balloting closes `failed`
 *    (WF-LEG-02 re-ballot posture: open a new ballot; the engine never
 *    auto-loops; a failed RE-election leaves the incumbent seated).
 */
class SpeakerElectionVote implements FormHandler
{
    public function __construct(
        private readonly SpeakerService $speaker,
    ) {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'speaker.ballot';
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
        $legislature = ChamberActor::legislature($payload, 'F-LEG-008');

        if (($payload['action'] ?? null) === 'open') {
            if ($actor !== null) {
                throw new ConstitutionalViolation(
                    'Speaker ballotings are opened by the system (first session) or an adopted '
                    . 'replace_speaker motion — members file their rankings, not openings.',
                    'Art. II §3'
                );
            }

            $vote = $this->speaker->openBallot($legislature);

            return [
                'legislature_id' => (string) $legislature->id,
                'action'         => 'open',
                'vote_id'        => (string) $vote->id,
                'vote_type'      => $vote->vote_type,
            ];
        }

        $member = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-008');

        $vote = $this->speaker->openBallotFor($legislature);

        if ($vote === null) {
            throw new ConstitutionalViolation(
                'No speaker balloting is open for this chamber.',
                'Art. II §3'
            );
        }

        $rankings = array_values(array_map('strval', (array) ($payload['rankings'] ?? [])));

        $result = $this->speaker->recordCast(
            $vote,
            $legislature,
            $member,
            $rankings,
            isset($payload['explanation']) ? (string) $payload['explanation'] : null,
        );

        return [
            'legislature_id' => (string) $legislature->id,
            'member_id'      => (string) $member->id,
        ] + $result;
    }
}
