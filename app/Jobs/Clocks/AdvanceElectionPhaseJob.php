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
 * CLK-01 election-subject phase timers (design §B.1):
 *
 *   payload.step = 'ranked_open'  @ elections.ranked_opens_at
 *                  → finalist_cutoff → ranked_open (F-IND-007 ballots commit)
 *   payload.step = 'ranked_close' @ elections.ranked_closes_at
 *                  → ranked_open → voting_closed, then hands the election
 *                    to tabulation: TabulateElectionJob on the
 *                    `long-running` Horizon queue (WI-B5 — dispatched only
 *                    once that job class lands; until then the election
 *                    rests at voting_closed for WI-B5 to pick up).
 *
 * Both moves are idempotent — an election not in the expected source
 * phase is left untouched (timers can re-fire safely).
 */
class AdvanceElectionPhaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** WI-B5's fan-out job (referenced by name — lands after this file). */
    private const TABULATE_JOB = 'App\\Jobs\\Elections\\TabulateElectionJob';

    public int $tries = 1;

    public function __construct(
        public readonly ?string $timerId = null,
        public readonly ?string $electionId = null,
        public readonly ?string $step = null,
    ) {
    }

    public function handle(ElectionLifecycleService $lifecycle): void
    {
        $timer = $this->timerId !== null ? ClockTimer::query()->find($this->timerId) : null;

        $electionId = $this->electionId
            ?? ($timer?->subject_type === 'election' ? $timer->subject_id : null);
        $step = $this->step ?? ($timer?->payload['step'] ?? null);

        if ($electionId === null || $step === null) {
            return;
        }

        $election = Election::query()->find($electionId);

        if ($election === null) {
            return;
        }

        if ($step === 'ranked_open') {
            $lifecycle->openRanked($election);

            return;
        }

        if ($step === 'ranked_close') {
            $before = $election->status;
            $lifecycle->closeRanked($election);

            if ($before === Election::STATUS_RANKED_OPEN
                && class_exists(self::TABULATE_JOB)) {
                (self::TABULATE_JOB)::dispatch($election->id)->onQueue('long-running');
            }
        }
    }
}
