<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\Committee;
use App\Models\Legislature;
use App\Models\User;
use App\Services\Legislature\CommitteeService;

/**
 * F-LEG-011 — Committee Chair/Alternate Vote (chamber ops §C.5).
 *
 * Whole-house RCV per committee: all serving members cast (Speaker
 * included — constitutive election); candidates = the committee's seated
 * members (R-12 requires R-11). Chair = majority-of-continuing winner
 * (PROTECTED countRcv); alternate = top runner-up by sequential exclusion
 * (the Phase B deriveAdvisors doctrine). Resolution rides the vote
 * engine's dispatch on auto-close.
 *
 * SYSTEM filing `{committee_id, action: 'open'}` (or the Speaker filing
 * it) opens the balloting after the F-SPK-005 assignment seats the
 * committee.
 */
class CommitteeChairVote implements FormHandler
{
    public function __construct(
        private readonly CommitteeService $committees,
    ) {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'committee.chair_ballot';
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
        $committee = Committee::query()->find($payload['committee_id'] ?? null);

        if ($committee === null) {
            throw new ConstitutionalViolation('F-LEG-011 requires a valid committee_id.', 'CGA Forms Catalog');
        }

        $legislature = Legislature::query()->findOrFail($committee->legislature_id);

        if (($payload['action'] ?? null) === 'open') {
            // System opens; the Speaker may also open (assignment admin).
            $opener = $actor !== null
                ? ChamberActor::speaker($actor, $legislature, 'F-LEG-011')
                : null;

            $vote = $this->committees->openChairBallot($committee, $opener);

            return [
                'committee_id' => (string) $committee->id,
                'action'       => 'open',
                'vote_id'      => (string) $vote->id,
            ];
        }

        $member = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-011');

        $vote = $this->committees->openChairBallotFor($committee);

        if ($vote === null) {
            throw new ConstitutionalViolation(
                'No chair balloting is open for this committee.',
                'CGA Forms Catalog (F-LEG-011)'
            );
        }

        $result = $this->committees->recordChairCast(
            $vote,
            $committee,
            $member,
            array_values(array_map('strval', (array) ($payload['rankings'] ?? []))),
            isset($payload['explanation']) ? (string) $payload['explanation'] : null,
        );

        return [
            'committee_id' => (string) $committee->id,
            'member_id'    => (string) $member->id,
        ] + $result;
    }
}
