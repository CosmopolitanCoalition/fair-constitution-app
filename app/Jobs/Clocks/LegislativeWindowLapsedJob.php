<?php

namespace App\Jobs\Clocks;

use App\Models\ClockTimer;
use App\Models\ConstitutionalChallenge;
use App\Services\AuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * CLK-12 fire handler (PHASE_E_DESIGN_challenge_law §B.7) — the legislative
 * remedy timeframe (§5.3) lapsed. This is a LIGHT marker job: it records that
 * the timeframe expired and updates the docket badge; it does NOT transition the
 * challenge. Path 3 belongs to CLK-11 (armed to the LATER of the two deadlines)
 * so the auto-remedy fires exactly once, only once BOTH windows have closed.
 *
 * Idempotent: a challenge already resolved (amended/overridden/remedied) is a
 * no-op marker.
 */
class LegislativeWindowLapsedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $timerId = null,
        public readonly ?string $challengeId = null,
    ) {}

    public function handle(AuditService $audit): void
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
            return; // already resolved — the marker is moot
        }

        $audit->append(
            module: 'judiciary',
            event: 'challenge.legislative_timeframe_lapsed',
            payload: [
                'challenge_id' => (string) $challenge->id,
                'note' => 'The legislative remedy timeframe (CLK-12) expired; the judicial veto window '
                    .'(CLK-11) governs the auto-remedy. No transition (Art. IV §5.5).',
            ],
            ref: 'CLK-12',
            jurisdictionId: (string) $challenge->jurisdiction_id,
        );
    }
}
