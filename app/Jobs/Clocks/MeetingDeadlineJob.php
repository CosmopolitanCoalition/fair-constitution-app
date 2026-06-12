<?php

namespace App\Jobs\Clocks;

use App\Models\ClockTimer;
use App\Models\Legislature;
use App\Models\LegislatureSession;
use App\Services\AuditService;
use App\Services\PublicRecordService;
use App\Services\SettingsResolver;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * CLK-02 — Legislature Meeting Deadline (Art. II §2: a chamber must meet
 * at least every max_days_between_meetings; WF-SYS-02 → WF-LEG-05).
 * Subject: legislature. Armed/re-armed by SessionService at every
 * quorum-met adjournment (and re-armed here on breach — the rolling
 * deadline never goes dark).
 *
 * Breach posture (the honest constitutional answer): the Template
 * provides no dissolution remedy and the system cannot teleport humans
 * into a room. On fire (idempotent — verifies no quorum-met meeting
 * since arm):
 *   1. audit entry + public_records row kind 'violation' citing
 *      Art. II §2;
 *   2. re-arm from the breach — a chamber that stays dark chains one
 *      violation per period, forever, on the public record. (Admin-office
 *      auto-intake consumes this from the chamber-ops scope when
 *      misconduct_investigations lands.)
 */
class MeetingDeadlineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $timerId = null,
    ) {
    }

    public function handle(
        AuditService $audit,
        PublicRecordService $records,
        SettingsResolver $settings,
        \App\Services\ClockService $clocks,
    ): void {
        $timer = $this->timerId !== null ? ClockTimer::query()->find($this->timerId) : null;

        $legislatureId = $timer?->subject_type === 'legislature' ? $timer->subject_id : null;

        if ($legislatureId === null) {
            return; // the fire itself is already chained
        }

        $legislature = Legislature::query()->find($legislatureId);

        if ($legislature === null) {
            return;
        }

        // Idempotency / staleness: a quorum-met session since this timer
        // armed means the chamber DID meet — the re-arm at adjournment
        // should have cancelled this timer; treat the fire as moot.
        $metSince = LegislatureSession::query()
            ->where('legislature_id', $legislature->id)
            ->where('quorum_met', true)
            ->where('opened_at', '>=', $timer->armed_at)
            ->exists();

        if ($metSince) {
            return;
        }

        $days  = $settings->resolveInt((string) $legislature->jurisdiction_id, 'max_days_between_meetings', 90);
        $since = $legislature->last_met_on?->toDateString() ?? 'its seating';

        DB::transaction(function () use ($audit, $records, $clocks, $legislature, $timer, $days, $since) {
            $audit->append(
                module: 'legislature',
                event: 'meeting_deadline.breached',
                payload: [
                    'legislature_id' => $legislature->id,
                    'timer_id'       => $timer->id,
                    'last_met_on'    => $legislature->last_met_on?->toDateString(),
                    'max_days'       => $days,
                ],
                ref: 'CLK-02',
                jurisdictionId: (string) $legislature->jurisdiction_id,
            );

            $records->publish(
                kind: 'violation',
                title: 'Meeting deadline breached — the chamber has not met within ' . $days . ' days',
                body: "Art. II §2 requires the legislature to meet at least every {$days} days; "
                    . "no quorum-verified session has occurred since {$since}. This violation is "
                    . 'recorded publicly and the deadline re-arms — the record chains one violation '
                    . 'per period until the chamber meets (WF-SYS-02).',
                attrs: [
                    'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                    'legislature_id'  => (string) $legislature->id,
                    'via_clock'       => 'CLK-02',
                    'subject_type'    => 'legislature',
                    'subject_id'      => (string) $legislature->id,
                ],
            );

            // Rolling deadline: re-arm from the breach.
            $anchor = Carbon::now()->startOfDay();
            $dueBy  = $anchor->copy()->addDays($days);

            $legislature->forceFill(['next_meeting_due_by' => $dueBy->toDateString()])->save();

            $clocks->arm(
                'CLK-02',
                (string) $legislature->jurisdiction_id,
                'legislature',
                (string) $legislature->id,
                $dueBy,
                ['derive' => ['anchor_at' => $anchor->toIso8601String(), 'unit' => 'days']],
            );
        });
    }
}
