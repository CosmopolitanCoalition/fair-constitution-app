<?php

namespace App\Jobs\Clocks;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Forms\Contracts\ElectionSchedulingDelegate;
use App\Domain\Forms\FormRegistry;
use App\Domain\Forms\NoopElectionSchedulingDelegate;
use App\Models\ClockTimer;
use App\Models\Election;
use App\Models\Legislature;
use App\Services\ElectionLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * CLK-01 'schedule_general' — General Election Interval (Art. II §2,
 * WF-ELE-01). Subject: legislature.
 *
 * Elections fire from clocks, never official discretion: this job runs as
 * SYSTEM ACTOR through `ConstitutionalEngine::file('F-ELB-001', null, …)`
 * (the null actor passes the role gate per the engine's system-filing
 * rule). It adopts the legislature's open-cycle election when one exists
 * (created at the prior certification with its approval phase already
 * open — design §B.2.1) and confirms the schedule from the
 * per-jurisdiction defaults; the board's own F-ELB-001 can only REFINE
 * those dates within bounds. There is no API to move this timer's
 * fires_at (the hardened no-skip guarantee).
 *
 * Bridge: until the orchestrator rebinds ElectionSchedulingDelegate from
 * the WI-B4 no-op to ElectionLifecycleService (ConstitutionProvider), the
 * engine filing would create elections without races or phase timers —
 * so while the no-op is bound this job takes the DIRECT lifecycle path
 * (same mutation, same audit posture).
 */
class ScheduleGeneralElectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $timerId = null,
        public readonly ?string $legislatureId = null,
    ) {
    }

    public function handle(ElectionLifecycleService $lifecycle): void
    {
        $timer = $this->timerId !== null ? ClockTimer::query()->find($this->timerId) : null;

        $legislatureId = $this->legislatureId
            ?? ($timer?->subject_type === 'legislature' ? $timer->subject_id : null);

        if ($legislatureId === null) {
            return; // nothing to schedule for — the fire is already chained
        }

        $legislature = Legislature::query()->find($legislatureId);

        if ($legislature === null) {
            return;
        }

        $delegateLive = FormRegistry::handlerFor('F-ELB-001') !== null
            && ! (app(ElectionSchedulingDelegate::class) instanceof NoopElectionSchedulingDelegate);

        if (! $delegateLive) {
            $lifecycle->scheduleGeneral($legislature, $timer);

            return;
        }

        // Engine path: adopt the open-cycle election when it exists and
        // confirm the per-jurisdiction default schedule.
        $existing = Election::query()
            ->where('legislature_id', $legislature->id)
            ->where('kind', Election::KIND_GENERAL)
            ->whereIn('status', [Election::STATUS_SCHEDULED, Election::STATUS_APPROVAL_OPEN])
            ->orderByDesc('created_at')
            ->first();

        $dates = $lifecycle->defaultDates($legislature->jurisdiction_id, $existing?->approval_opens_at);

        $payload = [
            'jurisdiction_id'       => $legislature->jurisdiction_id,
            'legislature_id'        => $legislature->id,
            'kind'                  => Election::KIND_GENERAL,
            'trigger'               => 'scheduled',
            'triggered_by_timer_id' => $timer?->id,
            'approval_opens_at'     => $dates['approval_opens_at']->toIso8601String(),
            'finalist_cutoff_at'    => $dates['finalist_cutoff_at']->toIso8601String(),
            'ranked_opens_at'       => $dates['ranked_opens_at']->toIso8601String(),
            'ranked_closes_at'      => $dates['ranked_closes_at']->toIso8601String(),
        ];

        if ($existing !== null) {
            $payload['election_id'] = $existing->id;
        }

        app(ConstitutionalEngine::class)->file('F-ELB-001', null, $payload);
    }
}
