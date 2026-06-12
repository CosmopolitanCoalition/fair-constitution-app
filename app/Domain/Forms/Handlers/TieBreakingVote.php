<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\ChamberVote;
use App\Models\User;
use App\Services\ChamberVoteService;

/**
 * F-SPK-004 — Tie-Breaking Vote (chamber ops §B.2) — THE only speaker
 * vote: the engine rule `speaker.tiebreak_only` (Art. II §3) rejects any
 * other speaker cast pre-commit. Delegates to the vote engine's
 * tiebreak() seam: vote must have closed `tied`, one vote must actually
 * resolve it (structurally majority-basis only — the tie-break never
 * manufactures a supermajority), the cast records `is_tiebreak` and the
 * outcome recomputes against the UNCHANGED peg threshold.
 */
class TieBreakingVote implements FormHandler
{
    public function __construct(
        private readonly ChamberVoteService $votes,
    ) {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'vote.tiebreak_filed';
    }

    public function requiredRoles(): array
    {
        return ['R-10'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'The tie-breaking vote is the Speaker\'s own — never the system\'s.',
                'Art. II §3'
            );
        }

        $vote = ChamberVote::query()->find($payload['vote_id'] ?? null);

        if ($vote === null) {
            throw new ConstitutionalViolation('F-SPK-004 requires a valid vote_id.', 'Art. II §3');
        }

        $resolved = $this->votes->tiebreak(
            $vote,
            $actor,
            (string) ($payload['value'] ?? ''),
            isset($payload['explanation']) ? (string) $payload['explanation'] : null,
        );

        return [
            'vote_id'          => (string) $resolved->id,
            'value'            => (string) $payload['value'],
            'outcome'          => (string) $resolved->outcome,
            'speaker_tiebreak' => true,
        ];
    }
}
