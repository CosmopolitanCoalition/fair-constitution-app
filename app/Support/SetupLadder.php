<?php

namespace App\Support;

use App\Models\InstanceSettings;

/**
 * THE WIZARD LADDER (operator ruling 2026-09-05, wizard-ladder A): Steps 0 to
 * 6, one page each. The scale choice and the simulate choice made at map
 * acceptance decide whether Steps 4 and 5 open; a step that does not apply is
 * skipped, never shown as pending.
 *
 *   0  Cosmic Address
 *   1  Constitutional Defaults
 *   2  Map Data
 *   3  Build Districts
 *   4  Scale Up Institutions   applies when institution_scale_mode = eager
 *   5  Simulate                applies when Step 4 applies, simulate_at_scale
 *                              is set and the world is a sandbox
 *   6  Confirm and Close
 *
 * Counter convention (unchanged): instance_settings.setup_step_completed = n
 * means steps 0..n-1 are done and the next step is n. A skipped step counts
 * as done for the ladder walk. Pure functions over the settings row, so the
 * routing is pinnable without a database.
 */
final class SetupLadder
{
    public const FIRST = 0;
    public const LAST  = 6;

    public const LABELS = [
        0 => 'Cosmic Address',
        1 => 'Constitutional Defaults',
        2 => 'Map Data',
        3 => 'Build Districts',
        4 => 'Scale Up Institutions',
        5 => 'Simulate',
        6 => 'Confirm & Close',
    ];

    private function __construct() {}

    /** Does step $n apply to this instance? */
    public static function applies(int $n, InstanceSettings $settings): bool
    {
        return match ($n) {
            4 => (string) ($settings->institution_scale_mode ?? 'eager') === 'eager',
            5 => self::applies(4, $settings)
                && (bool) $settings->simulate_at_scale
                && (string) $settings->game_mode === 'sandbox',
            default => $n >= self::FIRST && $n <= self::LAST,
        };
    }

    /**
     * The next open step: the first applicable step at or after the counter.
     * Returns LAST + 1 when every step is done.
     */
    public static function next(InstanceSettings $settings): int
    {
        $n = max(self::FIRST, (int) $settings->setup_step_completed);
        while ($n <= self::LAST && ! self::applies($n, $settings)) {
            $n++;
        }

        return $n;
    }

    /** Step $n is reachable when it applies and every applicable step before it is done. */
    public static function reachable(int $n, InstanceSettings $settings): bool
    {
        if ($n < self::FIRST || $n > self::LAST || ! self::applies($n, $settings)) {
            return false;
        }

        return $n <= self::next($settings);
    }

    /**
     * Mark step $n done: the counter advances to the next applicable step
     * after $n (skipped steps are folded in). Never moves backwards.
     */
    public static function completed(int $n, InstanceSettings $settings): int
    {
        $next = $n + 1;
        while ($next <= self::LAST && ! self::applies($next, $settings)) {
            $next++;
        }

        return max((int) $settings->setup_step_completed, $next);
    }

    /**
     * The stepper rows for the pages: n, label, applies, status
     * (done | current | reachable | locked | skipped).
     *
     * @return list<array{n:int,label:string,applies:bool,status:string}>
     */
    public static function describe(InstanceSettings $settings, ?int $current = null): array
    {
        $next = self::next($settings);
        $rows = [];
        for ($n = self::FIRST; $n <= self::LAST; $n++) {
            $applies = self::applies($n, $settings);
            if (! $applies) {
                $status = 'skipped';
            } elseif ($current !== null && $n === $current) {
                $status = 'current';
            } elseif ($n < $next) {
                $status = 'done';
            } elseif ($n === $next) {
                $status = 'reachable';
            } else {
                $status = 'locked';
            }
            $rows[] = ['n' => $n, 'label' => self::LABELS[$n], 'applies' => $applies, 'status' => $status];
        }

        return $rows;
    }
}
