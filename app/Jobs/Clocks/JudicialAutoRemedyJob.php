<?php

namespace App\Jobs\Clocks;

use App\Models\ClockTimer;
use App\Models\ConstitutionalChallenge;
use App\Services\Judiciary\JudicialRemedyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * CLK-11 fire handler (PHASE_E_DESIGN_challenge_law §B.6) — THE Art. IV §5 exit
 * criterion's automatic guarantee. CLK-11 is armed to max(veto_closes_at,
 * remedy_due_at), so when it fires BOTH §5.5 conditions are met ("does not
 * modify the law nor override the Judiciary within the window"). The job applies
 * the judiciary's own remedy directly to the law text.
 *
 * Idempotent: a challenge no longer at legislative_window_open (already amended
 * via Path 1, or overridden via Path 2 — which cancel the timer) is a no-op.
 * JudicialRemedyService::applyRemedy self-guards under lock.
 */
class JudicialAutoRemedyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $timerId = null,
        public readonly ?string $challengeId = null,
    ) {}

    public function handle(JudicialRemedyService $remedies): void
    {
        $timer = $this->timerId !== null ? ClockTimer::query()->find($this->timerId) : null;

        $challengeId = $this->challengeId
            ?? ($timer?->subject_type === 'constitutional_challenges' ? $timer->subject_id : null)
            ?? ($timer?->payload['challenge_id'] ?? null);

        if ($challengeId === null) {
            return;
        }

        $challenge = ConstitutionalChallenge::query()->find((string) $challengeId);

        if ($challenge === null
            || $challenge->status !== ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN) {
            return; // resolved before the sweep reached it — exactly the fire idempotency contract
        }

        $remedies->applyRemedy($challenge);
    }
}
