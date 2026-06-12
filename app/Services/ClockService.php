<?php

namespace App\Services;

use App\Jobs\Clocks\EvaluateCriticalPopulationJob;
use App\Jobs\Clocks\EvaluateResidencyThresholdsJob;
use App\Models\Clock;
use App\Models\ClockTimer;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * WI-6 — runtime API for the constitutional scheduler.
 *
 * arm()    — create an armed timer for a registry clock (deadline or
 *            threshold-watch). Audited (module 'clocks').
 * fire()   — transition armed → fired, append the chain entry, dispatch
 *            the mapped handler job (HANDLERS). Clock fires are SYSTEM
 *            events, not form filings — they append via AuditService
 *            directly, not through ConstitutionalEngine::file.
 * cancel() — transition armed → cancelled (subject resolved early).
 *
 * Amendable clock values are resolved per jurisdiction at EVALUATION time
 * via the constitutional_settings ancestor walk (SettingsResolver) — never
 * frozen at arm time. Threshold clocks (CLK-05, CLK-06) are additionally
 * evaluated directly by the EvaluateClocksJob sweep without needing an
 * armed timer (the cheap, always-on safety net).
 */
class ClockService
{
    /**
     * clock_id => handler job dispatched when the clock fires. Grows as
     * phases land (CLK-01 → ScheduleGeneralElectionJob in Phase B, …).
     * Fires for unmapped clocks still chain an audit entry — observable
     * before their machinery exists.
     */
    public const HANDLERS = [
        'CLK-05' => EvaluateResidencyThresholdsJob::class,
        'CLK-06' => EvaluateCriticalPopulationJob::class,
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly SettingsResolver $settings,
    ) {
    }

    /**
     * Arm a timer for a registry clock. $firesAt null = threshold-watch
     * (the sweep evaluates the watched quantity instead of a deadline).
     */
    public function arm(
        string $clockId,
        ?string $jurisdictionId = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?DateTimeInterface $firesAt = null,
        array $payload = [],
    ): ClockTimer {
        $clock = Clock::query()->find($clockId);

        if ($clock === null) {
            throw new InvalidArgumentException("Unknown clock [{$clockId}] — registry seeded?");
        }

        return DB::transaction(function () use ($clock, $jurisdictionId, $subjectType, $subjectId, $firesAt, $payload) {
            $timer = ClockTimer::create([
                'clock_id'        => $clock->id,
                'jurisdiction_id' => $jurisdictionId,
                'subject_type'    => $subjectType,
                'subject_id'      => $subjectId,
                'armed_at'        => now(),
                'fires_at'        => $firesAt,
                'state'           => ClockTimer::STATE_ARMED,
                'payload'         => $payload,
            ]);

            $this->audit->append(
                module: 'clocks',
                event: 'armed',
                payload: [
                    'timer_id'     => $timer->id,
                    'clock'        => $clock->id,
                    'subject_type' => $subjectType,
                    'subject_id'   => $subjectId,
                    'fires_at'     => $firesAt?->format(DateTimeInterface::ATOM),
                ],
                ref: $clock->id,
                jurisdictionId: $jurisdictionId,
            );

            return $timer;
        });
    }

    /**
     * Fire an armed timer: state → fired, audit entry, dispatch the mapped
     * handler job (after commit, on the default queue). Idempotent — a
     * timer that is no longer armed is left untouched.
     */
    public function fire(ClockTimer $timer, array $context = []): bool
    {
        $fired = DB::transaction(function () use ($timer, $context) {
            $fresh = ClockTimer::query()->whereKey($timer->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->state !== ClockTimer::STATE_ARMED) {
                return false;
            }

            $fresh->forceFill([
                'state'   => ClockTimer::STATE_FIRED,
                'payload' => array_merge($fresh->payload ?? [], $context, [
                    'fired_at' => now()->toIso8601String(),
                ]),
            ])->save();

            $clock = $fresh->clock;

            $this->audit->append(
                module: 'clocks',
                event: 'fired',
                payload: [
                    'timer_id'       => $fresh->id,
                    'clock'          => $fresh->clock_id,
                    'clock_name'     => $clock?->name,
                    'subject_type'   => $fresh->subject_type,
                    'subject_id'     => $fresh->subject_id,
                    'fires_workflow' => $clock?->fires_workflow,
                ],
                ref: $fresh->clock_id,
                jurisdictionId: $fresh->jurisdiction_id,
            );

            $timer->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });

        if ($fired && ($handler = self::HANDLERS[$timer->clock_id] ?? null) !== null) {
            $handler::dispatch();
        }

        return $fired;
    }

    /** Cancel an armed timer (subject resolved before the deadline). */
    public function cancel(ClockTimer $timer, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($timer, $reason) {
            $fresh = ClockTimer::query()->whereKey($timer->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->state !== ClockTimer::STATE_ARMED) {
                return false;
            }

            $fresh->forceFill([
                'state'   => ClockTimer::STATE_CANCELLED,
                'payload' => array_merge($fresh->payload ?? [], array_filter([
                    'cancelled_at'  => now()->toIso8601String(),
                    'cancel_reason' => $reason,
                ])),
            ])->save();

            $this->audit->append(
                module: 'clocks',
                event: 'cancelled',
                payload: [
                    'timer_id' => $fresh->id,
                    'clock'    => $fresh->clock_id,
                    'reason'   => $reason,
                ],
                ref: $fresh->clock_id,
                jurisdictionId: $fresh->jurisdiction_id,
            );

            $timer->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
    }

    /**
     * The clock's effective integer value for a jurisdiction, resolved at
     * EVALUATION time: per-jurisdiction constitutional_settings (ancestor
     * walk) when the clock is amendable and names a setting_key, else the
     * registry default, else $fallback.
     */
    public function resolvedInt(string $clockId, ?string $jurisdictionId, int $fallback): int
    {
        $clock = Clock::query()->find($clockId);

        if ($clock === null) {
            return $fallback;
        }

        $registryDefault = $clock->default_value['value'] ?? null;
        $default         = is_numeric($registryDefault) ? (int) $registryDefault : $fallback;

        $settingKey = $clock->settingKey();

        if ($clock->amendable && $settingKey !== null && $jurisdictionId !== null) {
            return $this->settings->resolveInt($jurisdictionId, $settingKey, $default);
        }

        return $default;
    }
}
