<?php

namespace App\Services\Legislature;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Committee;
use App\Models\CommitteePreference;
use App\Models\CommitteeSeat;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * F-SPK-005 — THE committee assignment algorithm (chamber ops §C.4).
 *
 * Deterministic and pure over a snapshot: assign() is a static function of
 * arrays (no DB, no clock, no RNG) so tests/Constitutional/
 * CommitteeAssignmentTest can pin it exhaustively. run() is the DB wrapper
 * the F-SPK-005 handler calls inside the engine transaction.
 *
 * The formula (WF-LEG-03 / committees.html): per-member share =
 * Total Reps ÷ (Committees × seats per committee); total committee seats
 * across all committees = the number of placements to fill. What it
 * constrains: placements distribute EVENLY across members (counts differ
 * by at most 1). It does not require P = M; rounds handle the general case.
 *
 * Steps:
 *  1. Partition by seat kind (unicameral = one 'all' partition).
 *  2. Budgets: floor(P/M) each; the P mod M extras go to the members with
 *     the highest vote_share_norm — the q-ledger #q2 tie-break currency
 *     (deterministic fallback: seat_no ASC nulls-last, then member uuid).
 *  3. Placement rounds: members owed an r-th placement are processed by
 *     preference depth; contested committees order contenders by
 *     vote_share_norm DESC — winners take seats (`tie_broken`/`tie_break`),
 *     losers' next preference is honored within the same pass.
 *  4. The partial unique committee_seats_one_live backstops "never two
 *     seats on one committee".
 *  5. Exhaustion guard: an exhausted preference list places the member on
 *     the open committee with the most remaining kind-seats
 *     (preference_rank_honored = NULL).
 *  6. All seats filled → rows are written `seated`, committees flip
 *     `seated`; the complete input/output snapshot is the F-SPK-005 audit
 *     payload.
 */
class CommitteeAssignmentService
{
    /** vote_share_norm is numeric(8,4) — compare as 1e4-scaled integers. */
    public const SHARE_SCALE = 10_000;

    public function __construct(
        private readonly AuditService $audit,
    ) {
    }

    // =========================================================================
    // The pure core (pinned DB-free)
    // =========================================================================

    /**
     * @param  array<string, array<string, int>>  $committees
     *         committee_id => (kind => open kind-seats), in CREATION ORDER
     *         (the default preference order). Unicameral committees use the
     *         single kind 'all'.
     * @param  array<string, array{kind: string, share: int, seat_no: int|null}>  $members
     *         member_id => facts; share = vote_share_norm × SHARE_SCALE.
     * @param  array<string, list<string>>  $preferences
     *         member_id => ordered committee ids; absent/empty = default
     *         (committee creation order — mockup rule).
     * @return array{
     *   placements: list<array{member_id: string, committee_id: string, seat_kind: string|null,
     *                          assigned_via: string, preference_rank_honored: int|null}>,
     *   partitions: array<string, array{p: int, m: int, base: int, extras: list<string>}>,
     *   contests: list<array{committee_id: string, kind: string, depth: int, open: int,
     *                        contenders: list<array{member_id: string, share: int}>,
     *                        winners: list<string>, losers: list<string>}>,
     *   exhaustion: list<array{member_id: string, committee_id: string}>,
     * }
     */
    public static function assign(array $committees, array $members, array $preferences): array
    {
        // kind => committee_id => open seats (creation order preserved).
        $kinds = [];
        foreach ($committees as $committeeId => $kindSeats) {
            foreach ($kindSeats as $kind => $count) {
                if ($count > 0) {
                    $kinds[$kind][$committeeId] = $count;
                }
            }
        }

        $defaultPrefs = array_keys($committees);

        $placements = [];
        $partitions = [];
        $contests   = [];
        $exhaustion = [];

        foreach ($kinds as $kind => $open) {
            $kindMemberIds = array_keys(array_filter($members, fn ($m) => $m['kind'] === $kind));
            sort($kindMemberIds, SORT_STRING); // deterministic iteration base

            $p = array_sum($open);
            $m = count($kindMemberIds);

            if ($m === 0) {
                throw new InvalidArgumentException(
                    "Partition [{$kind}] has {$p} seats but no serving members of that kind."
                );
            }

            $base   = intdiv($p, $m);
            $extraN = $p % $m;

            // Extras to the highest vote_share_norm (q-ledger #q2).
            $ranked = $kindMemberIds;
            usort($ranked, fn (string $a, string $b) => self::compareMembers($members[$a], $a, $members[$b], $b));
            $extras = array_slice($ranked, 0, $extraN);

            $budget = array_fill_keys($kindMemberIds, $base);
            foreach ($extras as $id) {
                $budget[$id]++;
            }

            $partitions[$kind] = ['p' => $p, 'm' => $m, 'base' => $base, 'extras' => $extras];

            $held   = array_fill_keys($kindMemberIds, []); // member => committee_id => true
            $rounds = $base + ($extraN > 0 ? 1 : 0);

            for ($round = 1; $round <= $rounds; $round++) {
                $pending = array_values(array_filter($kindMemberIds, fn ($id) => $budget[$id] >= $round));

                while ($pending !== []) {
                    // Current top unfulfilled preference per pending member.
                    $tops = [];
                    foreach ($pending as $id) {
                        $prefs = ($preferences[$id] ?? []) !== [] ? $preferences[$id] : $defaultPrefs;
                        $tops[$id] = null;

                        foreach (array_values($prefs) as $index => $committeeId) {
                            if (($open[$committeeId] ?? 0) > 0 && ! isset($held[$id][$committeeId])) {
                                $tops[$id] = ['committee_id' => $committeeId, 'depth' => $index + 1];
                                break;
                            }
                        }
                    }

                    // Exhaustion guard first: list ran out with seats open.
                    $exhausted = array_keys(array_filter($tops, fn ($top) => $top === null));

                    if ($exhausted !== []) {
                        foreach ($exhausted as $id) {
                            $committeeId = self::mostOpenCommittee($open, $defaultPrefs, $held[$id]);

                            $placements[] = [
                                'member_id'               => $id,
                                'committee_id'            => $committeeId,
                                'seat_kind'               => $kind === 'all' ? null : $kind,
                                'assigned_via'            => CommitteeSeat::VIA_ALGORITHM,
                                'preference_rank_honored' => null,
                            ];
                            $exhaustion[] = ['member_id' => $id, 'committee_id' => $committeeId];

                            $open[$committeeId]--;
                            $held[$id][$committeeId] = true;
                        }

                        $pending = array_values(array_diff($pending, $exhausted));
                        continue;
                    }

                    // Stage by preference depth: shallowest first.
                    $depth = min(array_map(fn ($top) => $top['depth'], $tops));

                    $byCommittee = [];
                    foreach ($tops as $id => $top) {
                        if ($top['depth'] === $depth) {
                            $byCommittee[$top['committee_id']][] = $id;
                        }
                    }

                    $placed = [];

                    foreach ($byCommittee as $committeeId => $contenders) {
                        $slots     = $open[$committeeId];
                        $contested = count($contenders) > $slots;

                        usort($contenders, fn (string $a, string $b) => self::compareMembers($members[$a], $a, $members[$b], $b));

                        $winners = $contested ? array_slice($contenders, 0, $slots) : $contenders;
                        $losers  = $contested ? array_slice($contenders, $slots) : [];

                        foreach ($winners as $id) {
                            $placements[] = [
                                'member_id'               => $id,
                                'committee_id'            => $committeeId,
                                'seat_kind'               => $kind === 'all' ? null : $kind,
                                'assigned_via'            => $contested ? CommitteeSeat::VIA_TIE_BREAK : CommitteeSeat::VIA_ALGORITHM,
                                'preference_rank_honored' => $depth,
                            ];

                            $open[$committeeId]--;
                            $held[$id][$committeeId] = true;
                            $placed[] = $id;
                        }

                        if ($contested) {
                            $contests[] = [
                                'committee_id' => $committeeId,
                                'kind'         => $kind,
                                'depth'        => $depth,
                                'open'         => $slots,
                                'contenders'   => array_map(
                                    fn (string $id) => ['member_id' => $id, 'share' => $members[$id]['share']],
                                    $contenders
                                ),
                                'winners' => $winners,
                                'losers'  => $losers,
                            ];
                        }
                        // Losers stay pending: their next preference is
                        // honored within the same pass (next while-iteration).
                    }

                    $pending = array_values(array_diff($pending, $placed));
                }
            }
        }

        return [
            'placements' => $placements,
            'partitions' => $partitions,
            'contests'   => $contests,
            'exhaustion' => $exhaustion,
        ];
    }

    /**
     * The #q2 ordering: vote_share_norm DESC, then seat_no ASC (nulls
     * last), then member uuid ASC — fully deterministic.
     *
     * @param  array{share: int, seat_no: int|null}  $a
     * @param  array{share: int, seat_no: int|null}  $b
     */
    public static function compareMembers(array $a, string $aId, array $b, string $bId): int
    {
        if ($a['share'] !== $b['share']) {
            return $b['share'] <=> $a['share'];
        }

        $aSeat = $a['seat_no'] ?? PHP_INT_MAX;
        $bSeat = $b['seat_no'] ?? PHP_INT_MAX;

        if ($aSeat !== $bSeat) {
            return $aSeat <=> $bSeat;
        }

        return strcmp($aId, $bId);
    }

    /**
     * Exhaustion-guard target: the open committee with the most remaining
     * kind-seats the member does not already sit on; ties resolve by
     * creation order (deterministic).
     *
     * @param  array<string, int>  $open
     * @param  list<string>  $creationOrder
     * @param  array<string, true>  $held
     */
    private static function mostOpenCommittee(array $open, array $creationOrder, array $held): string
    {
        $best     = null;
        $bestOpen = 0;

        foreach ($creationOrder as $committeeId) {
            $remaining = $open[$committeeId] ?? 0;

            if ($remaining > $bestOpen && ! isset($held[$committeeId])) {
                $best     = $committeeId;
                $bestOpen = $remaining;
            }
        }

        if ($best === null) {
            throw new InvalidArgumentException(
                'Exhaustion guard found no open committee — seat/budget bookkeeping violated P = Σ budgets.'
            );
        }

        return $best;
    }

    // =========================================================================
    // DB wrapper (called by the F-SPK-005 handler inside the engine txn)
    // =========================================================================

    /**
     * Run the assignment over the legislature's `created` committees (or an
     * explicit subset for mid-term additions). Writes committee_seats rows
     * (final state `seated` — the allocated/assigned/tie_broken progression
     * is preserved in the audit snapshot via assigned_via), flips committees
     * `seated`, and returns the full snapshot for the audit payload.
     *
     * @param  list<string>|null  $committeeIds  scope a mid-term run (WF-LEG-13)
     */
    public function run(Legislature $legislature, ?array $committeeIds = null): array
    {
        $committeeQuery = Committee::query()
            ->where('legislature_id', $legislature->id)
            ->where('status', Committee::STATUS_CREATED)
            ->orderBy('created_at')
            ->orderBy('id');

        if ($committeeIds !== null) {
            $committeeQuery->whereIn('id', $committeeIds);
        }

        $committees = $committeeQuery->get();

        if ($committees->isEmpty()) {
            throw new ConstitutionalViolation(
                'No created committees await assignment — file F-LEG-009 first.',
                'CGA Forms Catalog (F-SPK-005)'
            );
        }

        $bicameral = (int) $legislature->type_b_seats > 0;

        $committeeInput = [];
        foreach ($committees as $committee) {
            $committeeInput[(string) $committee->id] = $bicameral
                ? ['type_a' => (int) $committee->type_a_seats, 'type_b' => (int) $committee->type_b_seats]
                : ['all' => (int) $committee->seats];
        }

        $memberRows = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->current()
            ->get();

        $memberInput = [];
        foreach ($memberRows as $member) {
            $memberInput[(string) $member->id] = [
                'kind'    => $bicameral ? $member->seatKind() : 'all',
                'share'   => (int) round(((float) ($member->vote_share_norm ?? 0)) * self::SHARE_SCALE),
                'seat_no' => $member->seat_no !== null ? (int) $member->seat_no : null,
            ];
        }

        $preferenceRows = CommitteePreference::query()
            ->where('legislature_id', $legislature->id)
            ->get();

        $preferenceInput = [];
        foreach ($preferenceRows as $row) {
            // Only committees in this run's scope are honored.
            $preferenceInput[(string) $row->member_id] = array_values(array_filter(
                array_map('strval', (array) $row->rankings),
                fn (string $id) => isset($committeeInput[$id])
            ));
        }

        $result = self::assign($committeeInput, $memberInput, $preferenceInput);

        return DB::transaction(function () use ($legislature, $committees, $result, $committeeInput, $memberInput, $preferenceInput) {
            $now = now();

            foreach ($result['placements'] as $placement) {
                CommitteeSeat::create([
                    'committee_id'            => $placement['committee_id'],
                    'member_id'               => $placement['member_id'],
                    'seat_kind'               => $placement['seat_kind'],
                    'status'                  => CommitteeSeat::STATUS_SEATED,
                    'assigned_via'            => $placement['assigned_via'],
                    'preference_rank_honored' => $placement['preference_rank_honored'],
                    'seated_at'               => $now,
                ]);
            }

            Committee::query()
                ->whereIn('id', $committees->pluck('id'))
                ->update(['status' => Committee::STATUS_SEATED, 'updated_at' => $now]);

            return [
                'legislature_id' => (string) $legislature->id,
                'committees'     => $committeeInput,
                'members'        => $memberInput,
                'preferences'    => $preferenceInput,
                'partitions'     => $result['partitions'],
                'placements'     => $result['placements'],
                'contests'       => $result['contests'],
                'exhaustion'     => $result['exhaustion'],
            ];
        });
    }

    /**
     * WF-LEG-13 recheck — after any chamber countback/special seating:
     * recompute kind ratios + placement evenness and SURFACE drift; never
     * auto-rebalance (seated members are not unseated by arithmetic —
     * drift resolves only through vacancy events).
     *
     * @return list<array{committee_id: string, note: string}>
     */
    public function recheck(Legislature $legislature): array
    {
        $notes = [];

        $committees = Committee::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', [Committee::STATUS_CREATED, Committee::STATUS_SEATED])
            ->get();

        foreach ($committees as $committee) {
            $live = CommitteeSeat::query()
                ->where('committee_id', $committee->id)
                ->live()
                ->get();

            if ($live->count() < (int) $committee->seats) {
                $notes[] = [
                    'committee_id' => (string) $committee->id,
                    'note'         => sprintf(
                        '%d of %d seats filled — refill by whole-house RCV (WF-LEG-13).',
                        $live->count(),
                        (int) $committee->seats
                    ),
                ];
            }

            if ($committee->type_a_seats !== null) {
                $liveA = $live->where('seat_kind', 'type_a')->count();
                $liveB = $live->where('seat_kind', 'type_b')->count();

                if ($liveA !== (int) $committee->type_a_seats || $liveB !== (int) $committee->type_b_seats) {
                    $notes[] = [
                        'committee_id' => (string) $committee->id,
                        'note'         => sprintf(
                            'Kind ratio drifted: %da+%db live vs %da+%db allocated (Art. V §3 mirror).',
                            $liveA,
                            $liveB,
                            (int) $committee->type_a_seats,
                            (int) $committee->type_b_seats
                        ),
                    ];
                }
            }
        }

        return $notes;
    }
}
