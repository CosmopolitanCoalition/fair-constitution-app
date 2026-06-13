<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\ConstitutionalChallenge;
use App\Models\User;
use App\Services\Judiciary\ConstitutionalChallengeService;

/**
 * F-JDG-005 — Remedy Recommendation (Art. IV §5.2 second half + §5.3/§5.4
 * window-setting, WF-JUD-05). Filed by a seated judge (R-19/R-20) of the
 * challenge's court.
 *
 * This is where the judge SETS both windows (remedy_timeframe_days §5.3,
 * veto_window_days §5.4); the engine arms CLK-12 and CLK-11. The challenge lands
 * at legislative_window_open, the resting state while both clocks run, and the
 * legislature is "informed" on the record (§5.2).
 */
class RemedyRecommendation implements FormHandler
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
        return 'challenge.remedy';
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

        $seat = JudicialActor::seat($actor, (string) $challenge->judiciary_id, 'F-JDG-005');

        $recommendation = $this->challenges->recommendRemedy($challenge, [
            'remedy_kind' => (string) ($payload['remedy_kind'] ?? ''),
            'recommended_text' => isset($payload['recommended_text']) ? (string) $payload['recommended_text'] : null,
            'rationale_text' => (string) ($payload['rationale_text'] ?? ''),
            'remedy_timeframe_days' => (int) ($payload['remedy_timeframe_days'] ?? 0),
            'veto_window_days' => (int) ($payload['veto_window_days'] ?? 0),
        ]);

        return [
            'challenge_id' => (string) $challenge->id,
            'remedy_id' => (string) $recommendation->id,
            'remedy_kind' => (string) $recommendation->remedy_kind,
            'remedy_timeframe_days' => (int) $recommendation->remedy_timeframe_days,
            'veto_window_days' => (int) $recommendation->veto_window_days,
            'clk11_timer_id' => (string) $recommendation->clk11_timer_id,
            'clk12_timer_id' => (string) $recommendation->clk12_timer_id,
            'status' => (string) $challenge->refresh()->status,
            'filed_by_seat' => (string) $seat->id,
            'jurisdiction_id' => (string) $challenge->jurisdiction_id,
        ];
    }

    private function resolveChallenge(array $payload): ConstitutionalChallenge
    {
        $id = $payload['challenge_id'] ?? null;

        $challenge = is_string($id) ? ConstitutionalChallenge::query()->find($id) : null;

        if ($challenge === null) {
            throw new ConstitutionalViolation('F-JDG-005 names the challenge it recommends a remedy for (challenge_id).', 'Art. IV §5');
        }

        return $challenge;
    }
}
