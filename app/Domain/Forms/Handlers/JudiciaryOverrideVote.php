<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\ConstitutionalChallenge;
use App\Models\Law;
use App\Models\Legislature;
use App\Models\User;
use App\Services\Judiciary\JudiciaryOverrideService;

/**
 * F-LEG-035 — Judiciary Override Vote (Art. IV §5.4, WF-JUD-05, Path 2).
 *
 * Actor R-09 of the offending law's legislature. Opens a `judiciary_override`
 * supermajority vote (the PROTECTED threshold, never re-derived) against an open
 * challenge, WITHIN the CLK-11 judicial veto window. On adoption the finding is
 * overruled and the law stands unchanged; a failed override leaves the window
 * open (Path 1/3 remain). An override filed after CLK-11 closes is rejected
 * (Art. IV §5.4 binds it "within a set Judicial veto window").
 */
class JudiciaryOverrideVote implements FormHandler
{
    use ResolvesLegislativeActor;

    public function __construct(
        private readonly JudiciaryOverrideService $overrides,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'challenge.override_proposed';
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
        $challenge = ConstitutionalChallenge::query()->find((string) ($payload['challenge_id'] ?? ''));

        if ($challenge === null) {
            throw new ConstitutionalViolation('F-LEG-035 names the challenge it overrides (challenge_id).', 'Art. IV §5');
        }

        // The override is cast in the offending law's legislature.
        $law = Law::query()->findOrFail((string) $challenge->challenged_law_id);
        $legislature = Legislature::query()->find((string) $law->legislature_id);

        if ($legislature === null) {
            throw new ConstitutionalViolation(
                'The offending law has no legislature to override the finding — Art. IV §5.4.',
                'Art. IV §5'
            );
        }

        $proposer = $this->currentMemberOf($actor, (string) $legislature->id);

        $out = $this->overrides->propose(
            $legislature,
            $proposer,
            $challenge,
            isset($payload['dissent_text']) ? (string) $payload['dissent_text'] : null,
        );

        return $out + [
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
        ];
    }
}
