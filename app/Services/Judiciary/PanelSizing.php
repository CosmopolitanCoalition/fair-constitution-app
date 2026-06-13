<?php

namespace App\Services\Judiciary;

/**
 * The panel-sizing pure function (PHASE_E_DESIGN_cases_juries §D.1).
 *
 * The constitutional core (Art. IV §4): "The number of Judges sat to a case
 * should be at least three (3), Odd in number, and scale with the severity of
 * the controversies, offenses, and punishments. Constitutional Questions of
 * significant importance are heard by the entire court."
 *
 * DB-free and total — severity + the seated-judge count are the only inputs.
 * The `panels.size` CHECK (size >= 3 AND size % 2 = 1) is the DB belt behind
 * this; the judiciaries.min_judges >= 5 CHECK guarantees a `serious` case can
 * always seat 5.
 *
 * The severity→size ladder (minor=moderate=3, serious=5) is implementation-
 * chosen within the constitutional constraints (≥3, odd, monotonic, en-banc
 * for major) — the minimal lawful ladder (design §F deferral 5, q-ledger).
 *
 * PINNED EXHAUSTIVELY by PanelSizingTest. This class lives beside the other
 * pure cores; if an edit breaks the pins, the edit is a constitutional
 * violation — fix the edit, never the test.
 */
final class PanelSizing
{
    /**
     * @return array{size:int, en_banc:bool} — odd, ≥3, severity-scaled;
     *                                       en_banc ⇒ the entire seated court.
     */
    public static function sizeFor(string $severity, int $seatedJudges): array
    {
        if ($severity === 'constitutional_major') {
            // The ENTIRE court hears major constitutional questions (CLK-16).
            // Forced odd: an even seated count drops the lowest draw so the
            // en-banc bench stays odd (no ties).
            $size = $seatedJudges % 2 === 1 ? $seatedJudges : $seatedJudges - 1;

            return ['size' => max(3, $size), 'en_banc' => true];
        }

        // Severity ladder, clamped to the seated pool and forced odd:
        //   minor → 3, moderate → 3, serious → 5 (a court with ≥5 judges).
        $target = match ($severity) {
            'serious' => 5,
            default => 3,   // minor, moderate (and any unknown defaults to the floor)
        };

        // Never exceed the seated pool; never below 3; always odd.
        $size = min($target, $seatedJudges);

        if ($size % 2 === 0) {
            $size -= 1;   // force odd downward
        }

        return ['size' => max(3, $size), 'en_banc' => false];
    }
}
