<?php

namespace App\Support;

/**
 * ONE QUORUM FORMULA (operator ruling 2026-09-05, bench-and-quorum-law A).
 *
 *   quorum_required = 0                                     no seats
 *                   = min(seats, max(3, ceil(seats / 2)))   otherwise
 *
 * The stored number can never exceed the chamber: a 1-seat chamber convenes
 * with its 1 member, a 2-seat chamber with both. Every writer of
 * legislatures.quorum_required goes through required() or sql(). The live
 * quorum at vote time is ConstitutionalValidator::quorum() over serving
 * members, a different question.
 */
final class QuorumLaw
{
    private function __construct() {}

    public static function required(int $totalSeats): int
    {
        if ($totalSeats <= 0) {
            return 0;
        }

        return min($totalSeats, max(3, (int) ceil($totalSeats / 2)));
    }

    /** The SQL mirror over a seat expression. */
    public static function sql(string $seats): string
    {
        return "(CASE WHEN ({$seats}) <= 0 THEN 0
                      ELSE LEAST(({$seats}), GREATEST(3, CEIL(({$seats})::numeric / 2.0)))::int END)";
    }
}
