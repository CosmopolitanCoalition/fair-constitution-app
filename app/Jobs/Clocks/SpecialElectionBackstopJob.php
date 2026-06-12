<?php

namespace App\Jobs\Clocks;

use App\Models\ClockTimer;
use App\Models\Election;
use App\Models\Vacancy;
use App\Services\AuditService;
use App\Services\ElectionLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * CLK-04 — Special Election Window backstop (Art. II §5, WF-ELE-04).
 * Subject: vacancy; fires_at = declared_at + special_election_max_days
 * (the hard close; payload carries the window).
 *
 * Discretion can never produce "no election": if the window closes and
 * the vacancy is neither filled nor covered by a live special election,
 * this job appends a VIOLATION entry to the chain and force-schedules the
 * special election anyway (design §B.2.6 — "the SpecialElectionBackstopJob
 * at +180d appends a violation entry and force-schedules if a board
 * somehow cancelled"). A vacancy already filled, countback-running, or
 * covered by a live special election is a quiet no-op.
 */
class SpecialElectionBackstopJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $timerId = null,
        public readonly ?string $vacancyId = null,
    ) {
    }

    public function handle(ElectionLifecycleService $lifecycle, AuditService $audit): void
    {
        $timer = $this->timerId !== null ? ClockTimer::query()->find($this->timerId) : null;

        $vacancyId = $this->vacancyId
            ?? ($timer?->subject_type === 'vacancy' ? $timer->subject_id : null);

        if ($vacancyId === null) {
            return;
        }

        $vacancy = Vacancy::query()->find($vacancyId);

        if ($vacancy === null || $vacancy->status === Vacancy::STATUS_FILLED) {
            return;
        }

        // A live (non-cancelled) special election already covers the
        // vacancy — the backstop has nothing to do.
        if ($vacancy->special_election_id !== null) {
            $special = Election::query()->find($vacancy->special_election_id);

            if ($special !== null && $special->status !== Election::STATUS_CANCELLED) {
                return;
            }
        }

        // The window closed without an election — first-class violation
        // record, then the forced schedule.
        $audit->append(
            module: 'elections',
            event: 'special_election.backstopped',
            payload: [
                'vacancy_id'        => $vacancy->id,
                'vacancy_status'    => $vacancy->status,
                'timer_id'          => $timer?->id,
                'window'            => $timer?->payload ?? [],
                'violation'         => true,
                'citation'          => 'Art. II §5',
                'cancelled_special' => $vacancy->special_election_id,
            ],
            ref: 'CLK-04',
            jurisdictionId: $vacancy->jurisdiction_id,
            rejected: true,
            blockedReason: 'Special election window closed without a live election (Art. II §5) — force-scheduling.',
        );

        if ($vacancy->special_election_id !== null) {
            // The covered path above returned; reaching here means the
            // prior special was cancelled — clear it so the forced
            // schedule can attach.
            $vacancy->forceFill(['special_election_id' => null])->save();
        }

        $lifecycle->scheduleSpecial($vacancy, $timer, forced: true);
    }
}
