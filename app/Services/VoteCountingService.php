<?php

namespace App\Services;

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountbackResult;
use App\Domain\Counting\CountInput;
use App\Domain\Counting\CountResult;
use App\Domain\Counting\Micro;
use App\Domain\Counting\RoundResult;
use App\Domain\Engine\ConstitutionalViolation;

/**
 * PROTECTED FILE — Constitutional review required before modification.
 *
 * The constitutional counting engine. Implements:
 *   - Art. II §2  PR-STV: Droop quota floor(valid/(seats+1))+1, Weighted
 *                 Inclusive Gregory surplus transfers (ALL of the
 *                 winner's ballots move at weight × surplus/total, with
 *                 per-ballot truncation in 6-dp scaled integers)
 *   - Art. II §5  countback: full deterministic re-run at the original
 *                 seat count with the vacating candidacies struck
 *   - Art. III §3 single-winner RCV (instant-runoff, majority of
 *                 CONTINUING ballots) + top-4 advisors by sequential
 *                 exclusion
 *
 * PURITY CONTRACT (the protected property): no constructor dependencies,
 * no DB, no Eloquent, no clock, no RNG state, no config reads. Same
 * CountInput → byte-identical CountResult (incl. record_hash) on any
 * machine. All I/O lives in the tabulation jobs.
 *
 * FIXTURE-DERIVED SEMANTICS (mockups/electoral/results.html, the
 * 412,383-ballot Queens count — design §0): a candidate who reaches
 * quota becomes SURPLUS-PENDING and is recorded as elected only in the
 * round their surplus distributes (round_elected = surplus round).
 * Until that round they still RECEIVE transfers — proven by fixture
 * rounds 19–21, where Nora Whitfield (already over quota after round
 * 18: 20,253 + 23,642 accumulated = 43,895 > 41,239) receives 1,854
 * from Sam Porter's surplus, growing her own surplus to the recorded
 * 4,511; likewise Carl Jensen (+1,646, +3,083 → surplus 5,003) and Leo
 * Tanaka (+308 → surplus 977). The surplus queue therefore re-selects
 * the LARGEST CURRENT surplus each round, which reproduces the
 * fixture's exact ordering r19 Sam → r20 Nora → r21 Carl → r22 Leo.
 * Once a surplus distributes, the winner rests at exactly quota and
 * never receives again.
 *
 * TIE-BREAK (§A.5-T, "Art. II §4 principle · as implemented" — the
 * registry's prior-performance rule applied to the count itself):
 *   1. Backwards tie-break: compare the tied candidates' tallies in the
 *      most recent earlier round where they differ, walking back to
 *      round 1 (first preferences); narrow the tied set at each
 *      differing round.
 *   2. Audit-chained seeded lot: if tied in EVERY round, order by
 *      sha256(tie_seed ∥ candidacy_id) ascending, where
 *      tie_seed = sha256(tieSeedBase ∥ ':' ∥ round_no) and tieSeedBase
 *      = sha256(canonical-ballots-hash ∥ race_id) is supplied by the
 *      caller from public data. The seed and resulting order are
 *      published in the round record. Deterministic: no wall clock, no
 *      RNG state. The first candidate in the resolved order takes the
 *      action (is eliminated / distributes first / is seated first).
 *
 * Numeric strategy (§B.2): fixed-point integers at 10⁻⁶ (microvotes),
 * truncation toward zero, an explicit per-round truncation residue so
 * conservation holds with ==:
 *   Σ tallies + exhausted + residue == total_valid × SCALE, every round.
 *
 * Pinned by tests/Constitutional/{StvDroopGregoryTest,
 * CountbackUniversalTest, RcvTest}. CI gates on the suite. If an edit
 * to this file breaks those tests, the edit is the violation — fix the
 * edit, never the test.
 */
final class VoteCountingService
{
    /** Part of record_hash: any algorithm change is a visibly different engine. */
    public const ENGINE_VERSION = 'stv-droop-wig/1.0.0';

    /** Microvotes per vote (see Micro::SCALE). */
    public const SCALE = Micro::SCALE;

    private const CONTINUING = 0;

    private const PENDING = 1;       // at/over quota, surplus not yet distributed — still receives

    private const ELECTED = 2;       // surplus distributed (rests at quota) or shortcut-filled

    private const ELIMINATED = 3;

    /**
     * PR-STV with Droop quota and Weighted Inclusive Gregory surplus
     * transfers. Fills all seats of one race in one count (Art. II §2).
     */
    public function countStv(CountInput $in): CountResult
    {
        return $this->run($in, 'stv');
    }

    /**
     * Single-winner instant-runoff (Art. III §3). Win condition is a
     * majority of CONTINUING (non-exhausted) ballots, so exhaustion can
     * never deadlock the count; the stored quota field is the display
     * majority floor(total_valid/2)+1 (mockup convention).
     */
    public function countRcv(CountInput $in): CountResult
    {
        if ($in->seats !== 1) {
            throw new \InvalidArgumentException('countRcv() fills exactly one seat; use countStv() for multi-seat races.');
        }

        return $this->run($in, 'rcv');
    }

    /**
     * Top-4 advisors by sequential exclusion (Art. III §3, WF-ELE-08):
     * re-run the count without the winner, then without the top two,
     * and so on — five full IRV counts over the same ballot set.
     *
     * @return array{0: ?CountResult, 1: ?CountResult, 2: ?CountResult, 3: ?CountResult, 4: ?CountResult}
     *         index 0 = base count, 1–4 = advisor ranks; ranks that
     *         cannot be derived (fewer candidates, ballots exhausted)
     *         stay null — those advisor seats remain vacant
     */
    public function deriveAdvisors(CountInput $in): array
    {
        $results = [null, null, null, null, null];
        $excluded = $in->excluded;

        for ($rank = 0; $rank <= 4; $rank++) {
            $remaining = array_diff($in->candidacyIds, $excluded);

            if ($remaining === []) {
                break;
            }

            $run = $this->countRcv(new CountInput(
                $in->candidacyIds,
                1,
                $in->ballots,
                array_values($excluded),
                $in->tieSeedBase,
            ));

            if ($run->totalValid === 0 || $run->elected === []) {
                break;
            }

            $results[$rank] = $run;
            $excluded[] = $run->elected[0]['candidacy_id'];
        }

        return $results;
    }

    /**
     * Universal countback (Art. II §5): a full deterministic re-run of
     * the ORIGINAL race — same ballots, same seat count, hence the same
     * quota — with the vacating candidacies struck, "as if the vacating
     * member never ran". Replacements are the re-run winners who are not
     * current sitting members, in re-run election order: countback fills
     * the vacancy, it never re-litigates held seats.
     *
     * NO filter parameter of any kind exists on this signature — who may
     * benefit from a countback is decided by the ballots alone.
     * Universality is structural, not policy (q-ledger #q6); pinned by
     * CountbackUniversalTest via reflection.
     *
     * @param  list<string>  $struck   vacating candidacy ids (multiple on simultaneous vacancies)
     * @param  list<string>  $sitting  candidacy ids currently holding seats in this race
     */
    public function countback(CountInput $in, array $struck, array $sitting): CountbackResult
    {
        $rerun = $this->countStv(new CountInput(
            $in->candidacyIds,
            $in->seats,
            $in->ballots,
            array_values(array_unique(array_merge($in->excluded, $struck))),
            $in->tieSeedBase,
        ));

        $sittingSet = array_fill_keys($sitting, true);
        $replacements = [];

        foreach ($rerun->elected as $e) {
            if (! isset($sittingSet[$e['candidacy_id']])) {
                $replacements[] = $e['candidacy_id'];
            }
        }

        return new CountbackResult(
            tabulation: $rerun,
            struck: array_values($struck),
            sitting: array_values($sitting),
            replacements: $replacements,
            failed: count($replacements) < count($struck),
        );
    }

    // ------------------------------------------------------------------
    // Core engine — pure, integer-only, deterministic.
    // ------------------------------------------------------------------

    private function run(CountInput $in, string $mode): CountResult
    {
        if ($in->seats < 1 || $in->seats > 9) {
            throw new ConstitutionalViolation(
                "Race seat count {$in->seats} outside the constitutional 1–9 range.",
                'Art. II §2/§8',
            );
        }

        // -- Candidate index: sorted candidacy uuids (determinism §B.4).
        //    Excluded candidacies (pre-lock withdrawals, countback strikes)
        //    leave the candidate universe ENTIRELY — they hold no pile, are
        //    never elected (not even by shortcut fill), and never appear in
        //    round records: "as if the vacating member never ran" (Art. II §5).
        $excluded = array_fill_keys($in->excluded, true);

        $ids = [];
        foreach (array_unique($in->candidacyIds) as $cid) {
            if (! isset($excluded[$cid])) {
                $ids[] = $cid;
            }
        }
        sort($ids, SORT_STRING);
        $idx = array_flip($ids);
        $n = count($ids);

        // -- Canonicalize + group ballots: strip unknown/excluded ids
        //    (preferences pass over them, never exhaust on them), collapse
        //    duplicates keeping the first, drop empty → invalid.
        $canon = [];

        foreach ($in->ballots->groups() as $group) {
            $seen = [];
            $prefs = '';

            foreach ($group['ranking'] as $cid) {
                if (! isset($idx[$cid]) || isset($excluded[$cid]) || isset($seen[$cid])) {
                    continue;
                }
                $seen[$cid] = true;
                $prefs .= pack('v', $idx[$cid]);
            }

            if ($prefs === '') {
                continue; // invalid ballot — excluded from total_valid
            }

            $canon[$prefs] = ($canon[$prefs] ?? 0) + $group['count'];
        }

        ksort($canon, SORT_STRING);
        $totalValid = array_sum($canon);

        $quota = $mode === 'stv'
            ? intdiv($totalValid, $in->seats + 1) + 1   // Droop, computed once, never recomputed
            : intdiv($totalValid, 2) + 1;               // RCV display majority (win test uses continuing)
        $quotaMicro = $quota * Micro::SCALE;

        // -- Round 0 state: parallel arrays (memory: groups ≈ distinct rankings).
        $gKey = [];
        $gPos = [];
        $gW = [];
        $gMult = [];
        $piles = array_fill(0, max($n, 1), []);
        $tally = array_fill(0, max($n, 1), 0);
        $status = array_fill(0, max($n, 1), self::CONTINUING);

        $g = 0;
        foreach ($canon as $key => $mult) {
            $gKey[$g] = $key;
            $gPos[$g] = 0;
            $gW[$g] = Micro::SCALE;
            $gMult[$g] = $mult;
            $first = ord($key[0]) | (ord($key[1]) << 8);
            $piles[$first][] = $g;
            $tally[$first] += $mult * Micro::SCALE;
            $g++;
        }

        $exhaustedCum = 0;
        $residueCum = 0;
        $roundNo = 0;
        $rounds = [];
        $elected = [];          // [{candidacy_id, round, seat_no}] in election order
        $history = [];          // round_no → round-START tally array (for §A.5-T stage 1)

        // Next continuing-or-pending preference of group $g, or null.
        $nextPref = function (int $g) use (&$gKey, &$gPos, &$status): ?array {
            $key = $gKey[$g];
            $len = strlen($key) >> 1;
            for ($p = $gPos[$g] + 1; $p < $len; $p++) {
                $c = ord($key[2 * $p]) | (ord($key[2 * $p + 1]) << 8);
                if ($status[$c] <= self::PENDING) {
                    return [$p, $c];
                }
            }

            return null;
        };

        $snapshot = function (bool $withoutQuota = false, ?array $tieBreak = null) use (&$tally, &$status, &$exhaustedCum, &$elected, $ids, $n): array {
            $cands = [];
            for ($c = 0; $c < $n; $c++) {
                if ($status[$c] !== self::ELIMINATED) {
                    $cands[$ids[$c]] = $tally[$c];
                }
            }
            ksort($cands, SORT_STRING);

            $payload = [
                'candidates' => $cands,
                'exhausted_micro' => $exhaustedCum,
                'elected_so_far' => array_column($elected, 'candidacy_id'),
                'elected_without_quota' => $withoutQuota,
            ];

            if ($tieBreak !== null) {
                $payload['tie_break'] = $tieBreak;
            }

            return $payload;
        };

        /**
         * §A.5-T. $tied = candidate indexes, $pickHighest: true → prior-
         * performance leader acts first (surplus order, shortcut order);
         * false → prior-performance trailer is eliminated.
         * Returns [chosen index, tie_break payload|null].
         */
        $resolveTie = function (array $tied, bool $pickHighest, int $forRound) use (&$history, &$roundNo, $ids, $in): array {
            if (count($tied) === 1) {
                return [$tied[0], null];
            }

            $set = array_values($tied);

            for ($r = $roundNo; $r >= 1; $r--) {
                $vals = [];
                foreach ($set as $c) {
                    $vals[$c] = $history[$r][$c];
                }
                if (count(array_unique($vals)) > 1) {
                    $extreme = $pickHighest ? max($vals) : min($vals);
                    $set = array_keys($vals, $extreme, true);
                    if (count($set) === 1) {
                        return [$set[0], ['stage' => 'prior_rounds', 'decided_at_round' => $r]];
                    }
                }
            }

            $seed = hash('sha256', $in->tieSeedBase . ':' . $forRound);
            usort($set, fn (int $a, int $b) => strcmp(
                hash('sha256', $seed . $ids[$a]),
                hash('sha256', $seed . $ids[$b]),
            ));

            return [$set[0], ['stage' => 'lot', 'seed' => $seed, 'order' => array_map(fn (int $c) => $ids[$c], $set)]];
        };

        $toList = function (array $to) use ($ids): array {
            $out = [];
            foreach ($to as $c => $amt) {
                if ($amt > 0) {
                    $out[$ids[$c]] = $amt;
                }
            }
            ksort($out, SORT_STRING);

            return array_map(null, array_keys($out), array_values($out));
        };

        $maxRounds = 4 * $n + 2 * $in->seats + 8; // hard safety bound — every round removes a candidate or fills a seat

        while (true) {
            if ($roundNo > $maxRounds) {
                throw new \LogicException('Counting engine exceeded its round bound — invariant breach.');
            }

            // (a) Election check: continuing candidates at/over quota become
            //     surplus-pending. (RCV never uses quota crossing.)
            if ($mode === 'stv') {
                for ($c = 0; $c < $n; $c++) {
                    if ($status[$c] === self::CONTINUING && $tally[$c] >= $quotaMicro) {
                        $status[$c] = self::PENDING;
                    }
                }
            }

            if (count($elected) === $in->seats) {
                break; // final winner's surplus round already emitted below
            }

            $pending = [];
            $continuing = [];
            for ($c = 0; $c < $n; $c++) {
                if ($status[$c] === self::PENDING) {
                    $pending[] = $c;
                } elseif ($status[$c] === self::CONTINUING) {
                    $continuing[] = $c;
                }
            }

            // (b) Surplus queue first: largest CURRENT surplus distributes
            //     (fixture rounds 19–22; ties → §A.5-T).
            if ($mode === 'stv' && $pending !== []) {
                $best = max(array_map(fn (int $c) => $tally[$c], $pending));
                [$p, $tieBreak] = $resolveTie(
                    array_values(array_filter($pending, fn (int $c) => $tally[$c] === $best)),
                    true,
                    $roundNo + 1,
                );

                $T = $tally[$p];
                $S = $T - $quotaMicro;

                $roundNo++;
                $tallies = $snapshot();
                $history[$roundNo] = $tally;

                $to = [];
                $exh = 0;
                $moved = 0;

                if ($S > 0) {
                    foreach ($piles[$p] as $gi) {
                        $w2 = Micro::mulDiv($gW[$gi], $S, $T); // per-ballot truncation (§B.2)
                        if ($w2 === 0) {
                            continue; // whole share < 1 µv → residue
                        }
                        $amt = $gMult[$gi] * $w2;
                        $next = $nextPref($gi);
                        if ($next === null) {
                            $exh += $amt; // exhausted share is lost to exhausted, not retained
                        } else {
                            [$pp, $c] = $next;
                            $gW[$gi] = $w2;
                            $gPos[$gi] = $pp;
                            $piles[$c][] = $gi;
                            $tally[$c] += $amt;
                            $to[$c] = ($to[$c] ?? 0) + $amt;
                        }
                        $moved += $amt;
                    }
                }

                $residue = $S - $moved;
                $residueCum += $residue;
                $exhaustedCum += $exh;

                $tally[$p] = $quotaMicro; // winner rests at exactly quota
                $piles[$p] = [];
                $status[$p] = self::ELECTED;
                $elected[] = ['candidacy_id' => $ids[$p], 'round' => $roundNo, 'seat_no' => count($elected) + 1];
                $tallies['elected_so_far'] = array_column($elected, 'candidacy_id');

                $rounds[] = new RoundResult($roundNo, 'elect', $ids[$p], [
                    'kind' => 'surplus',
                    'value_micro' => $S > 0 ? Micro::mulDiv(Micro::SCALE, $S, $T) : 0,
                    'to' => $toList($to),
                    'exhausted_micro' => $exh,
                    'truncation_residue_micro' => $residue,
                    'tie_break' => $tieBreak,
                ], $tallies);

                continue;
            }

            $unfilled = $in->seats - count($elected);

            // RCV win check: majority of continuing ballots.
            if ($mode === 'rcv' && $continuing !== []) {
                $sumContinuing = 0;
                foreach ($continuing as $c) {
                    $sumContinuing += $tally[$c];
                }

                $winner = null;
                $withoutMajority = false;
                foreach ($continuing as $c) {
                    if (2 * $tally[$c] > $sumContinuing) {
                        $winner = $c;
                        break;
                    }
                }
                if ($winner === null && count($continuing) === 1) {
                    $winner = $continuing[0]; // zero-ballot degenerate — cannot deadlock
                    $withoutMajority = true;
                }

                if ($winner !== null) {
                    $roundNo++;
                    $tallies = $snapshot($withoutMajority);
                    $history[$roundNo] = $tally;
                    $status[$winner] = self::ELECTED;
                    $elected[] = ['candidacy_id' => $ids[$winner], 'round' => $roundNo, 'seat_no' => 1];
                    $tallies['elected_so_far'] = array_column($elected, 'candidacy_id');
                    $rounds[] = new RoundResult($roundNo, 'elect', $ids[$winner], null, $tallies);
                    break;
                }
            }

            // (d) Shortcut fill: continuing == unfilled seats (STV) — declare
            //     them elected, descending tally, one round each (termination
            //     guarantee; not demonstrated in the Queens fixture).
            if ($mode === 'stv' && count($continuing) <= $unfilled) {
                while ($continuing !== []) {
                    $best = max(array_map(fn (int $c) => $tally[$c], $continuing));
                    [$w, $tieBreak] = $resolveTie(
                        array_values(array_filter($continuing, fn (int $c) => $tally[$c] === $best)),
                        true,
                        $roundNo + 1,
                    );

                    $roundNo++;
                    $tallies = $snapshot(true, $tieBreak);
                    $history[$roundNo] = $tally;
                    $status[$w] = self::ELECTED; // rests at held value — no surplus exists
                    $elected[] = ['candidacy_id' => $ids[$w], 'round' => $roundNo, 'seat_no' => count($elected) + 1];
                    $tallies['elected_so_far'] = array_column($elected, 'candidacy_id');
                    $rounds[] = new RoundResult($roundNo, 'elect', $ids[$w], null, $tallies);

                    $continuing = array_values(array_filter($continuing, fn (int $c) => $c !== $w));
                }
                break;
            }

            if ($continuing === []) {
                break; // no candidates left to act on (degenerate input)
            }

            // (c) Eliminate the lowest continuing candidate; ballots move at
            //     their CURRENT (possibly fractional) weight.
            $low = min(array_map(fn (int $c) => $tally[$c], $continuing));
            [$e, $tieBreak] = $resolveTie(
                array_values(array_filter($continuing, fn (int $c) => $tally[$c] === $low)),
                false,
                $roundNo + 1,
            );

            $roundNo++;
            $tallies = $snapshot();
            $history[$roundNo] = $tally;

            $to = [];
            $exh = 0;

            foreach ($piles[$e] as $gi) {
                $amt = $gMult[$gi] * $gW[$gi];
                if ($amt === 0) {
                    continue;
                }
                $next = $nextPref($gi);
                if ($next === null) {
                    $exh += $amt;
                } else {
                    [$pp, $c] = $next;
                    $gPos[$gi] = $pp;
                    $piles[$c][] = $gi;
                    $tally[$c] += $amt;
                    $to[$c] = ($to[$c] ?? 0) + $amt;
                }
            }

            $exhaustedCum += $exh;
            $tally[$e] = 0;
            $piles[$e] = [];
            $status[$e] = self::ELIMINATED;

            $rounds[] = new RoundResult($roundNo, 'eliminate', $ids[$e], [
                'kind' => 'elimination',
                'value_micro' => null, // "current value" — per-ballot weights vary
                'to' => $toList($to),
                'exhausted_micro' => $exh,
                'truncation_residue_micro' => 0,
                'tie_break' => $tieBreak,
            ], $tallies);
        }

        $finalTallies = [];
        for ($c = 0; $c < $n; $c++) {
            if ($status[$c] !== self::ELIMINATED) {
                $finalTallies[$ids[$c]] = $tally[$c];
            }
        }
        ksort($finalTallies, SORT_STRING);

        return new CountResult(
            engineVersion: self::ENGINE_VERSION,
            kind: $mode,
            seats: $in->seats,
            quota: $quota,
            totalValid: $totalValid,
            rounds: $rounds,
            elected: $elected,
            seatsUnfilled: $in->seats - count($elected),
            exhaustedMicro: $exhaustedCum,
            truncationResidueMicro: $residueCum,
            finalTallies: $finalTallies,
        );
    }
}
