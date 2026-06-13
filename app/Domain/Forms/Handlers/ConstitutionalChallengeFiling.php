<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;
use App\Services\Judiciary\ConstitutionalChallengeService;

/**
 * F-IND-016 — Constitutional Challenge Filing (Art. IV §5.1, WF-JUD-05).
 *
 * Actor R-03 (any active residency association covering the challenge
 * jurisdiction). The right is ABSOLUTE: §5.1 grants it to "all individuals who
 * inhabit a Jurisdiction"; Art. I makes it fee-free and condition-free. The
 * engine's validate stage runs guardAutomaticRights on F-IND-016 — any
 * eligibility test smuggled into the payload is itself unconstitutional —
 * and F-IND-016 is in EMERGENCY_PROTECTED_FORMS (an emergency power can never
 * suspend the right to challenge).
 *
 * Creates the challenge (`filed`) and, when an operating court exists, opens the
 * hearing case (`under_review`). When no court is seated the challenge parks at
 * `filed` — never rejected.
 */
class ConstitutionalChallengeFiling implements FormHandler
{
    public function __construct(
        private readonly ConstitutionalChallengeService $challenges,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'challenge.filed';
    }

    public function requiredRoles(): array
    {
        return ['R-03'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        // R-03 is enforced by the engine authorize stage; a null actor is a
        // system filing (not a path the rights surface uses, but defensive).
        $challenge = $this->challenges->file($actor, $payload);

        // Auto-open the hearing when an operating court is seated (the cases
        // agent accepts + panels via F-JDG-001). A parked (no-court) challenge
        // stays `filed`; the docket surfaces it when a court activates.
        $caseId = null;

        if ($challenge->status === \App\Models\ConstitutionalChallenge::STATUS_FILED
            && \App\Models\Judiciary::query()
                ->whereKey((string) $challenge->judiciary_id)
                ->whereIn('status', \App\Models\Judiciary::OPERATING_STATUSES)
                ->exists()) {
            $case = $this->challenges->openHearing($challenge);
            $caseId = (string) $case->id;
        }

        return [
            'challenge_id' => (string) $challenge->id,
            'challenged_law_id' => (string) $challenge->challenged_law_id,
            'claimed_basis' => (string) $challenge->claimed_basis,
            'status' => (string) $challenge->refresh()->status,
            'case_id' => $caseId,
            'jurisdiction_id' => (string) $challenge->jurisdiction_id,
        ];
    }
}
