<?php

namespace App\Services\Demo;

use App\Domain\Counting\Micro;
use InvalidArgumentException;

/**
 * Expands a jurisdiction's synthetic electorate into GROUPED ranked ballots —
 * exactly the shape `BallotSet::fromGrouped()` consumes.
 *
 * WHY THIS EXISTS. The Attained (Phase O) materializes a planet whose
 * electorates run to billions. Writing one ballot row per voter is impossible
 * (~8.35e9 rows per election cycle, terabytes, and a serialized audit append
 * per filing). It is also unnecessary: `BallotSet` stores identical rankings
 * once with a multiplicity, and `VoteCountingService` re-canonicalizes into
 * that same shape before any arithmetic. A cohort expanded into weighted groups
 * therefore produces a count that is IDENTICAL — not approximate — to expanding
 * every individual voter. Pinned by WeightedBallotIdentityTest.
 *
 * WHERE IT LIVES, AND WHY NOT NEXT DOOR. `app/Domain/Counting` is inside
 * `ConstitutionalVersionService::HARDENED_SURFACE`; adding any file there moves
 * the derived `constitutional_version`, and `ElectionResultsCertification`
 * refuses to certify an election whose pinned version moved mid-flight. So this
 * generator sits OUTSIDE the hardened surface and merely feeds it. The counting
 * engine is driven, never copied.
 *
 * PURITY CONTRACT (mirrors VoteCountingService's own): no DB, no clock, no
 * config, and NO PROCESS-GLOBAL RNG. `mt_srand()` — which
 * tests/Support/SyntheticBallotGenerator uses, correctly, for a test — is
 * global state; a pull worker looping thousands of races beside other consumers
 * cannot rely on it. Randomness here is a SHA-256 hash chain seeded from the
 * caller's string, so the same seed yields the same electorate on any box, in
 * any order, forever. That is what makes `sim:revert` cheap: the world is a
 * function, so deleting it loses nothing.
 */
final class CohortBallotExpander
{
    /**
     * Round-0 tallying does `$tally[$first] += $mult * Micro::SCALE` — a RAW
     * native multiply, not `Micro::mulDiv`. So a single group's contribution
     * must stay inside PHP's integer range. Earth's ~8.35e9 leaves ~1,100×
     * headroom; a "year 2300" operator dial would not, and would overflow
     * SILENTLY into a wrong count. Refuse loudly instead.
     */
    public const MAX_ELECTORATE = PHP_INT_MAX / Micro::SCALE;

    private function __construct() {}

    /**
     * @param  string  $seed  deterministic seed, e.g. hash(jurisdiction_id).':'.version
     * @param  list<string>  $candidacyIds  the race's countable candidacies
     * @param  int  $electorate  total ballots cast (turnout already applied)
     * @param  int  $groups  target number of DISTINCT rankings
     * @param  int  $clusters  preference archetypes to draw orderings from
     * @return list<array{0: list<string>, 1: int}> BallotSet::fromGrouped() shape
     */
    public static function expand(
        string $seed,
        array $candidacyIds,
        int $electorate,
        int $groups = 64,
        int $clusters = 6,
    ): array {
        $candidacyIds = array_values($candidacyIds);
        $n = count($candidacyIds);

        if ($n === 0) {
            throw new InvalidArgumentException('CohortBallotExpander: a race needs at least one candidacy.');
        }

        if (count(array_unique($candidacyIds)) !== $n) {
            throw new InvalidArgumentException('CohortBallotExpander: candidacy ids must be unique.');
        }

        if ($electorate < 1) {
            throw new InvalidArgumentException('CohortBallotExpander: electorate must be at least 1.');
        }

        if ($electorate > self::MAX_ELECTORATE) {
            throw new InvalidArgumentException(sprintf(
                'CohortBallotExpander: electorate %s exceeds the counting engine\'s raw-multiply ceiling '
                .'(%s = PHP_INT_MAX / Micro::SCALE). Round-0 tallying would overflow silently and produce a '
                .'wrong count rather than an error.',
                number_format($electorate),
                number_format(self::MAX_ELECTORATE),
            ));
        }

        $groups = max(1, $groups);
        $clusters = max(1, $clusters);

        $rng = new HashChainRandom($seed);

        // Popularity weights — what makes some candidates broadly preferred.
        $popularity = [];
        for ($i = 0; $i < $n; $i++) {
            $popularity[$i] = $rng->between(1, 100);
        }

        // Preference archetypes: weighted shuffles of the full candidate list.
        $orderings = [];
        for ($k = 0; $k < $clusters; $k++) {
            $pool = $popularity;
            $order = [];
            while ($pool !== []) {
                $total = array_sum($pool);
                $pick = $rng->between(1, $total);
                foreach ($pool as $i => $w) {
                    $pick -= $w;
                    if ($pick <= 0) {
                        $order[] = $i;
                        unset($pool[$i]);
                        break;
                    }
                }
            }
            $orderings[$k] = $order;
        }

        // Draw DISTINCT rankings with an integer weight each. Cost is bounded by
        // $groups, never by the electorate — the whole point.
        $maxLen = min($n, 12);
        $weighted = [];

        for ($g = 0; $g < $groups; $g++) {
            $order = $orderings[$rng->between(0, $clusters - 1)];
            $len = max(1, (int) round(($rng->between(1, $maxLen) + $rng->between(1, $maxLen)) / 2));
            $prefs = array_slice($order, 0, $len);

            $swaps = $rng->between(0, 2);
            for ($s = 0; $s < $swaps && $len > 1; $s++) {
                $i = $rng->between(0, $len - 2);
                [$prefs[$i], $prefs[$i + 1]] = [$prefs[$i + 1], $prefs[$i]];
            }

            $key = implode(',', $prefs);
            $weighted[$key] = ($weighted[$key] ?? 0) + $rng->between(1, 100);
        }

        return self::apportion($weighted, $electorate, $candidacyIds);
    }

    /**
     * Distribute the electorate across the drawn rankings so the multiplicities
     * sum EXACTLY to it — largest remainder over integer weights, ties broken by
     * key order. No float arithmetic touches a multiplicity.
     *
     * ⚠ This apportions BALLOTS TO RANKINGS. It is emphatically NOT the seating
     * law, which apportions SEATS to jurisdictions and where largest-remainder
     * is forbidden (settled 2026-07-13: giant-cascade, districts round to
     * nearest, never total-forced). Different domain, no conflict — but say so
     * out loud, because a reader who sees "largest remainder" in this codebase
     * is right to reach for the alarm.
     *
     * @param  array<string,int>  $weighted
     * @param  list<string>  $candidacyIds
     * @return list<array{0: list<string>, 1: int}>
     */
    private static function apportion(array $weighted, int $electorate, array $candidacyIds): array
    {
        ksort($weighted, SORT_STRING);

        $totalWeight = array_sum($weighted);
        $keys = array_keys($weighted);

        $counts = [];
        $assigned = 0;
        $remainders = [];

        foreach ($keys as $idx => $key) {
            $exact = $electorate * $weighted[$key];
            $whole = intdiv($exact, $totalWeight);
            $counts[$idx] = $whole;
            $assigned += $whole;
            $remainders[$idx] = $exact - $whole * $totalWeight;
        }

        // Hand out what rounding left over, largest remainder first.
        $left = $electorate - $assigned;
        if ($left > 0) {
            $order = array_keys($remainders);
            usort($order, function (int $a, int $b) use ($remainders) {
                return $remainders[$b] <=> $remainders[$a] ?: $a <=> $b;
            });

            foreach ($order as $idx) {
                if ($left <= 0) {
                    break;
                }
                $counts[$idx]++;
                $left--;
            }
        }

        $out = [];
        foreach ($keys as $idx => $key) {
            if ($counts[$idx] < 1) {
                continue; // BallotSet requires multiplicity >= 1
            }

            $ranking = array_map(
                static fn (string $i): string => $candidacyIds[(int) $i],
                explode(',', $key),
            );

            $out[] = [$ranking, $counts[$idx]];
        }

        return $out;
    }
}
