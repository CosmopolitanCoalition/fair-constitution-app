<?php

namespace Tests\Support;

/**
 * Seeded synthetic ranked-ballot generator (design §C.2): popularity-
 * weighted candidate draw + preference-cluster sampling, deterministic
 * for a given seed (mt_srand — stable across PHP releases since 7.1).
 *
 * Used by the constitutional property tests and the (test-excluded)
 * tinker performance check. Ballots are regenerated in-process, never
 * committed.
 */
final class SyntheticBallotGenerator
{
    /**
     * @param  list<string>  $candidateIds
     * @return list<array{0: list<string>, 1: int}> grouped rankings for BallotSet::fromGrouped()
     */
    public static function grouped(int $seed, int $ballots, array $candidateIds, int $clusters = 6): array
    {
        mt_srand($seed);

        $n = count($candidateIds);

        // Popularity weights per candidate.
        $weights = [];
        for ($i = 0; $i < $n; $i++) {
            $weights[$i] = mt_rand(1, 100);
        }

        // Cluster orderings: weighted shuffles of the full candidate list.
        $orderings = [];
        for ($k = 0; $k < $clusters; $k++) {
            $pool = $weights;
            $order = [];
            while ($pool !== []) {
                $total = array_sum($pool);
                $pick = mt_rand(1, $total);
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

        $groups = [];

        for ($b = 0; $b < $ballots; $b++) {
            $order = $orderings[mt_rand(0, $clusters - 1)];

            // Ranking length: 1..min(n, 12), biased toward mid-length.
            $maxLen = min($n, 12);
            $len = max(1, (int) round((mt_rand(1, $maxLen) + mt_rand(1, $maxLen)) / 2));

            $prefs = array_slice($order, 0, $len);

            // Local noise: a few adjacent swaps.
            $swaps = mt_rand(0, 2);
            for ($s = 0; $s < $swaps && $len > 1; $s++) {
                $i = mt_rand(0, $len - 2);
                [$prefs[$i], $prefs[$i + 1]] = [$prefs[$i + 1], $prefs[$i]];
            }

            $key = implode(',', $prefs);
            $groups[$key] = ($groups[$key] ?? 0) + 1;
        }

        $out = [];
        foreach ($groups as $key => $count) {
            $ranking = array_map(
                fn (string $i) => $candidateIds[(int) $i],
                explode(',', (string) $key),
            );
            $out[] = [$ranking, $count];
        }

        return $out;
    }

    /** Convenience: deterministic fake candidacy UUIDs c-0001… */
    public static function candidateIds(int $n): array
    {
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $ids[] = sprintf('cand-%04d-0000-0000-000000000000', $i);
        }

        return $ids;
    }
}
