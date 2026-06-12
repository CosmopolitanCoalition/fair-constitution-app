<?php

namespace App\Services;

use App\Models\Clock;
use App\Models\ClockTimer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * C-B2 (PHASE_C_DESIGN_votes_laws §C.5) — after a setting bill enacts,
 * re-derive the armed deadline timers whose clocks resolve from the
 * changed setting key (CLK-01 ← election_interval_months, CLK-02 ←
 * max_days_between_meetings, …).
 *
 * NO-SKIP DISCIPLINE (ElectionClockTest pins): armed timers are NEVER
 * moved — re-derivation is CANCEL + RE-ARM through ClockService's only
 * write paths, producing a fresh audited timer with the re-derived
 * deadline plus a 'clocks/rederived' chain entry naming old and new.
 * ClockService's public surface stays exactly {arm, fire, cancel,
 * resolvedInt}; this service holds no timer-write code of its own. The
 * input is a SETTING KEY, never a date — officials cannot reach this
 * with a target time; the arithmetic is fully derived:
 *
 *   fires_at = payload.derive.anchor_at + resolved_value(unit) − lead_days
 *
 * Bridge: CLK-01 'schedule_general' timers armed before Phase C lack the
 * derive payload — for those (and only those) the anchor falls back to
 * armed_at (CLK-01 is armed inside the certification transaction, so
 * armed_at ≡ certified_at) with lead_days resolved from current settings,
 * exactly the armNextGeneralElection formula.
 *
 * CLK-03 is deliberately NEVER re-derived (flagged decision, Art. II §7):
 * an active emergency power keeps its DECLARED duration; a lowered max
 * binds only new declarations/renewals.
 */
class ClockRederivationService
{
    public function __construct(
        private readonly ClockService $clocks,
        private readonly SettingsResolver $settings,
        private readonly AuditService $audit,
    ) {
    }

    /** @return int number of timers re-derived (cancel + re-arm pairs) */
    public function rederiveForSetting(string $settingKey, string $jurisdictionId): int
    {
        $clockIds = Clock::query()
            ->where('amendable', true)
            ->whereRaw("default_value->>'setting_key' = ?", [$settingKey])
            ->pluck('id')
            ->reject(fn (string $id) => $id === 'CLK-03') // declared durations stand
            ->values();

        if ($clockIds->isEmpty()) {
            return 0;
        }

        $rederived = 0;

        $timers = ClockTimer::query()
            ->armed()
            ->whereIn('clock_id', $clockIds)
            ->whereNotNull('fires_at')
            ->get();

        foreach ($timers as $timer) {
            $timerJid = $timer->jurisdiction_id !== null ? (string) $timer->jurisdiction_id : null;

            if ($timerJid === null || ! $this->selfOrDescendant($timerJid, $jurisdictionId)) {
                continue;
            }

            $derive = $timer->payload['derive'] ?? null;

            // Pre-Phase-C CLK-01 bridge (see class docblock).
            if ($derive === null && $timer->clock_id === 'CLK-01' && ($timer->payload['step'] ?? null) === 'schedule_general') {
                $derive = [
                    'anchor_at' => Carbon::parse($timer->armed_at)->toIso8601String(),
                    'unit'      => 'months',
                    'lead_days' => max(1, $this->settings->resolveInt($timerJid, 'ranked_window_days', 14))
                        + 1 // ElectionLifecycleService::RANKED_GAP_DAYS
                        + max(1, $this->settings->resolveInt($timerJid, 'approval_min_days', 30)),
                ];
            }

            if (! is_array($derive) || ! isset($derive['anchor_at'], $derive['unit'])) {
                continue; // no anchor — never guess a derivation
            }

            $value   = $this->clocks->resolvedInt($timer->clock_id, $timerJid, 0);
            $firesAt = self::deriveFiresAt($derive, $value);

            if ($firesAt === null) {
                continue;
            }

            $old = Carbon::parse($timer->fires_at);

            if ($firesAt->equalTo($old)) {
                continue; // arithmetic unchanged — nothing to record
            }

            DB::transaction(function () use ($timer, $derive, $firesAt, $old, $settingKey, $value) {
                // CANCEL + RE-ARM — never move (the no-skip invariant).
                if (! $this->clocks->cancel($timer, "rederived: {$settingKey} changed by legislative act")) {
                    return; // raced: no longer armed
                }

                $replacement = $this->clocks->arm(
                    $timer->clock_id,
                    $timer->jurisdiction_id,
                    $timer->subject_type,
                    $timer->subject_id,
                    $firesAt,
                    array_merge(
                        // carry forward routing keys (step etc.), drop runtime stamps
                        collect($timer->payload ?? [])->except(['cancelled_at', 'cancel_reason', 'fired_at'])->all(),
                        ['derive' => $derive, 'rederived_from' => (string) $timer->id],
                    ),
                );

                // CLK-02 banner coherence: chamber-home reads
                // legislatures.next_meeting_due_by.
                if ($timer->clock_id === 'CLK-02' && $timer->subject_type === 'legislature') {
                    DB::table('legislatures')
                        ->where('id', $timer->subject_id)
                        ->update(['next_meeting_due_by' => $firesAt->toDateString(), 'updated_at' => now()]);
                }

                $this->audit->append(
                    module: 'clocks',
                    event: 'rederived',
                    payload: [
                        'cancelled_timer_id' => (string) $timer->id,
                        'replacement_timer_id' => (string) $replacement->id,
                        'clock'              => $timer->clock_id,
                        'setting_key'        => $settingKey,
                        'new_value'          => $value,
                        'old_deadline'       => $old->toIso8601String(),
                        'new_deadline'       => $firesAt->toIso8601String(),
                    ],
                    ref: $timer->clock_id,
                    jurisdictionId: $timer->jurisdiction_id,
                );
            });

            $rederived++;
        }

        return $rederived;
    }

    /**
     * The PURE derivation arithmetic (pinned by SettingEnactmentTest):
     *
     *   fires_at = anchor_at + value(unit) − lead_days
     *
     * 'months' carries an optional lead_days subtraction (CLK-01: the
     * approval/ranked lead frozen at arm time so re-derivation moves only
     * the interval); 'days' is the plain rolling deadline (CLK-02).
     * Unknown units derive nothing — never guess.
     */
    public static function deriveFiresAt(array $derive, int $value): ?Carbon
    {
        if (! isset($derive['anchor_at'], $derive['unit'])) {
            return null;
        }

        $anchor = Carbon::parse($derive['anchor_at']);

        $firesAt = match ($derive['unit']) {
            'months' => $anchor->copy()->addMonths($value),
            'days'   => $anchor->copy()->addDays($value),
            default  => null,
        };

        return $firesAt?->subDays((int) ($derive['lead_days'] ?? 0));
    }

    /** Bounded parent_id walk: is $candidate the changed jurisdiction or inside its subtree? */
    private function selfOrDescendant(string $candidate, string $ancestor): bool
    {
        if ($candidate === $ancestor) {
            return true;
        }

        $current = $candidate;

        for ($depth = 0; $depth < 32; $depth++) {
            $parent = DB::table('jurisdictions')->where('id', $current)->value('parent_id');

            if ($parent === null) {
                return false;
            }

            if ((string) $parent === $ancestor) {
                return true;
            }

            $current = (string) $parent;
        }

        return false;
    }
}
