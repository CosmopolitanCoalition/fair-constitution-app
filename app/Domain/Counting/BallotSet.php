<?php

namespace App\Domain\Counting;

/**
 * Canonicalized, grouped ballot rankings — the counting core's only
 * ballot representation.
 *
 * Identical rankings are grouped with a multiplicity, and groups are
 * iterated in sorted-key order, so ballot *insertion order can never
 * matter* to a count (determinism guarantee, design §B.4) and memory
 * stays proportional to the number of DISTINCT rankings, not ballots.
 *
 * A ranking is an ordered list of candidacy UUIDs. Nothing else about
 * a ballot (voter, time, channel, write-in/finalist status of the
 * candidacy) exists in this type — by construction, not by rule.
 */
final class BallotSet implements \Countable
{
    /** @var array<string, array{ranking: list<string>, count: int}> sorted by key */
    private array $groups = [];

    private int $total = 0;

    private function __construct()
    {
    }

    /**
     * @param  iterable<list<string>>  $rankings  one ordered candidacy-id list per ballot
     */
    public static function fromRankings(iterable $rankings): self
    {
        $set = new self;

        foreach ($rankings as $ranking) {
            $set->add(array_values($ranking), 1);
        }

        ksort($set->groups, SORT_STRING);

        return $set;
    }

    /**
     * Pre-grouped construction (fixtures, generators): each entry is
     * [ranking ids, multiplicity].
     *
     * @param  iterable<array{0: list<string>, 1: int}>  $groups
     */
    public static function fromGrouped(iterable $groups): self
    {
        $set = new self;

        foreach ($groups as [$ranking, $count]) {
            $set->add(array_values($ranking), $count);
        }

        ksort($set->groups, SORT_STRING);

        return $set;
    }

    private function add(array $ranking, int $count): void
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Ballot multiplicity must be >= 1.');
        }

        $key = implode("\x1f", $ranking);

        if (isset($this->groups[$key])) {
            $this->groups[$key]['count'] += $count;
        } else {
            $this->groups[$key] = ['ranking' => $ranking, 'count' => $count];
        }

        $this->total += $count;
    }

    /** @return array<string, array{ranking: list<string>, count: int}> sorted by key */
    public function groups(): array
    {
        return $this->groups;
    }

    /** Raw ballot count (before per-count validation/canonicalization). */
    public function count(): int
    {
        return $this->total;
    }
}
