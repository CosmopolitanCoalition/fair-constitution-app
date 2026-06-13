<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\ConstitutionalChallenge;
use App\Models\User;
use App\Services\Judiciary\JudicialRemedyService;

/**
 * F-JDG-006 — Judicial Remedy Application (Art. IV §5.5, WF-JUD-05). THE exit
 * criterion's explicit judicial invocation: a seated judge (R-19/R-20) applies
 * the recommended remedy directly the moment BOTH windows have demonstrably
 * expired, without waiting for the CLK-11 sweep — the same applyRemedy body the
 * JudicialAutoRemedyJob runs automatically.
 *
 * The clock-fired path is the automatic guarantee; this handler is the judge's
 * explicit invocation. Both converge on JudicialRemedyService::applyRemedy,
 * which gates on now ≥ max(veto_closes_at, remedy_due_at) and
 * status='legislative_window_open' under lock.
 */
class JudicialRemedyApplication implements FormHandler
{
    public function __construct(
        private readonly JudicialRemedyService $remedies,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'challenge.judicial_remedy';
    }

    public function requiredRoles(): array
    {
        return ['R-19', 'R-20'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $challenge = $this->resolveChallenge($payload);

        $seat = JudicialActor::seat($actor, (string) $challenge->judiciary_id, 'F-JDG-006');

        $law = $this->remedies->applyRemedy($challenge);

        if ($law === null) {
            throw new ConstitutionalViolation(
                'This challenge is not at the legislative-window-open stage — there is no remedy to apply '
                .'(it has already been amended, overridden, or remedied).',
                'Art. IV §5'
            );
        }

        $challenge->refresh();

        return [
            'challenge_id' => (string) $challenge->id,
            'law_id' => (string) $law->id,
            'act_number' => (string) $law->act_number,
            'version_no' => (int) $law->current_version_no,
            'law_status' => (string) $law->status,
            'status' => (string) $challenge->status,
            'filed_by_seat' => (string) $seat->id,
            'jurisdiction_id' => (string) $challenge->jurisdiction_id,
        ];
    }

    private function resolveChallenge(array $payload): ConstitutionalChallenge
    {
        $id = $payload['challenge_id'] ?? null;

        $challenge = is_string($id) ? ConstitutionalChallenge::query()->find($id) : null;

        if ($challenge === null) {
            throw new ConstitutionalViolation('F-JDG-006 names the challenge it remedies (challenge_id).', 'Art. IV §5');
        }

        return $challenge;
    }
}
