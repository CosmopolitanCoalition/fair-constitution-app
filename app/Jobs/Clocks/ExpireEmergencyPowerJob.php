<?php

namespace App\Jobs\Clocks;

use App\Models\ClockTimer;
use App\Models\EmergencyPower;
use App\Services\EmergencyPowerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * CLK-03 (C-10) — emergency-power auto-expiry: "nothing rolls over
 * silently" (Art. II §7). Fires at emergency_powers.expires_at; flips the
 * power to `expired` with a full audit entry + public record ("no action
 * required" — and none is possible: there is no extend/pause/defer API
 * anywhere, only the F-LEG-025 fresh-supermajority renewal BEFORE
 * expiry).
 *
 * Idempotent: a power already resolved (renewed re-armed a fresh timer;
 * struck by Phase E review) is left untouched. A renewed power's STALE
 * timer never reaches here — renewal cancels + re-arms — but the service
 * re-checks liveness under lock anyway.
 */
class ExpireEmergencyPowerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $timerId = null,
        public readonly ?string $powerId = null,
    ) {
    }

    public function handle(EmergencyPowerService $powers): void
    {
        $timer = $this->timerId !== null ? ClockTimer::query()->find($this->timerId) : null;

        $powerId = $this->powerId
            ?? ($timer?->subject_type === 'emergency_power' ? $timer->subject_id : null);

        if ($powerId === null) {
            return;
        }

        $power = EmergencyPower::query()->find($powerId);

        if ($power === null) {
            return;
        }

        // The declared duration governs — never expire early (a re-fired
        // stale timer after a renewal must be a no-op).
        if ($power->expires_at !== null && $power->expires_at->isFuture()) {
            return;
        }

        $powers->expire($power);
    }
}
