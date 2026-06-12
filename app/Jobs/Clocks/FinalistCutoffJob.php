<?php

namespace App\Jobs\Clocks;

use App\Models\ClockTimer;
use App\Models\Election;
use App\Services\ElectionLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * CLK-18 close / CLK-21 — the finalist cutoff (design §B.2.2). Subject:
 * election; fires_at = elections.finalist_cutoff_at.
 *
 * Delegates to ElectionLifecycleService::applyFinalistCutoff — one
 * transaction: final standings rollup frozen (is_frozen = true, archived
 * to the chain), top X = finalist_count per race → 'finalist', the rest →
 * 'non_finalist' (WRITE-IN ELIGIBLE — the right to stand is preserved),
 * withdrawals locked (ballot lock), election → finalist_cutoff.
 * Idempotent: an election no longer in approval_open is left untouched.
 */
class FinalistCutoffJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $timerId = null,
        public readonly ?string $electionId = null,
    ) {
    }

    public function handle(ElectionLifecycleService $lifecycle): void
    {
        $timer = $this->timerId !== null ? ClockTimer::query()->find($this->timerId) : null;

        $electionId = $this->electionId
            ?? ($timer?->subject_type === 'election' ? $timer->subject_id : null);

        if ($electionId === null) {
            return;
        }

        $election = Election::query()->find($electionId);

        if ($election === null) {
            return;
        }

        $lifecycle->applyFinalistCutoff($election);
    }
}
