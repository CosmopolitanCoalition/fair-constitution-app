<?php

namespace App\Services\Legislature;

use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE TYPE B DISTRICT MAPPER — stage two of the Type B ladder (Wave 3, lane 1).
 *
 * When even 2-per-constituent overflows Type A (TypeBSeatLadder returns
 * needs_districting=true), whole sibling constituents are clumped into shared
 * representative PANELS. Each panel is then elected in its OWN at-large STV race
 * of rep_floor seats among its own constituents' residents (operator ruling
 * 2026-07-29 — one at-large race PER CLUMP, never one pooled race). This is a
 * balanced partition over the adjacency graph, never a cut through geometry.
 *
 * OPERATOR RULINGS B1–B7 (brief docs/plans/scaling/TYPE_B_DISTRICT_MAPPER_DESIGN.md):
 *  B1 — one at-large race PER PANEL (clump), each electing rep_floor seats; the
 *       chamber total = panel_count × rep_floor. (This SUPERSEDES the pre-2026-07-29
 *       pooled "ONE race" reading; racePlan/createRaces emit the per-panel races,
 *       keyed by election_races.type_b_panel_id.)
 *  B2 — panels are as EQUAL as possible (representation stays equal). Where a
 *       clean equal partition is impossible, the least remainder is borne by the
 *       LOWEST-POPULATION jurisdictions — the ONE place population is consulted,
 *       and only for the residual, never for compactness.
 *  B3 — zero-population constituents are INERT (no seats, not grouped). The
 *       combined cap (in TypeBSeatLadder) already floors seats to population.
 *  B4 — islands: adjacency for a disconnected constituent set falls back to
 *       centroid nearest-approach (the endorsed realisation of "nearest islands
 *       clump"; see B4_HULL_ISLAND_VALIDATION.md). Populated here by
 *       $centroids; the land-border graph is used wherever an edge exists.
 *  B5 — compactness tie-break: MAX TOTAL INTERNAL SHARED-BORDER LENGTH
 *       (jurisdiction_adjacency.border_len), final fallback lowest member id.
 *  B6 — NEVER cross-parent: the constituent set is exactly one parent's direct
 *       children; no edge or panel ever spans parents.
 *  B7 — a grouping is VERSIONED (draft/active/archived); a geodata change drafts
 *       a fresh grouping while sitting members serve out.
 *
 * NO geometry is ever cut: this is a balanced grouping over the constituent
 * adjacency graph, not a drawing operation. DRIFT law holds — seats are exact by
 * construction (panel_count × rep_floor), never total-forced.
 *
 * computePanels() is pure (no DB) and pinned like TypeBSeatLadder; apply()
 * loads the constituent graph, persists the grouping trio, recomputes
 * type_b_seats and clears type_b_needs_districting — the R-A guard un-blocks the
 * Type B race the instant the flag clears.
 */
class TypeBDistrictMapper
{
    public const TIE_BREAK_KEY = 'max_internal_border_len';

    /**
     * Pure grouping computation. Deterministic; no database, no geometry cut.
     *
     * @param array<string,int>              $populations  constituent id => population
     * @param array<string,array<string,float>> $adjacency  id => [neighbourId => border_len] (land borders, dim ≥ 1)
     * @param array<string,array{x:float,y:float}> $centroids id => centroid (island / disconnected fallback, B4)
     * @return array{
     *   panels: list<list<string>>, rep_floor:int, panel_count:int, seats:int,
     *   inert: list<string>, group_size:int, bound:int, undercount:bool,
     *   tie_break_key:string
     * }
     */
    public static function computePanels(
        array $populations,
        array $adjacency,
        int $typeA,
        int $population,
        int $repFloor,
        array $centroids = [],
    ): array {
        $repFloor = max($repFloor, TypeBSeatLadder::MIN_REP);

        // B3: zero-population constituents are inert — no seats, not grouped.
        $inhabited = [];
        $inert     = [];
        foreach ($populations as $id => $pop) {
            if ((int) $pop > 0) {
                $inhabited[(string) $id] = (int) $pop;
            } else {
                $inert[] = (string) $id;
            }
        }
        sort($inert);

        $n = count($inhabited);
        // B3 combined cap mirrored here so the mapper's bound matches the ladder.
        $bound = min($typeA, max(0, $population - $typeA));

        if ($n === 0) {
            return self::result([], $repFloor, 0, $inert, $bound, false);
        }

        // Maximum panels the bound allows; each panel seats rep_floor (B1).
        $pMax = intdiv($bound, $repFloor);
        if ($pMax < 1) {
            // The B3 combined cap leaves less than one full panel's worth of
            // population headroom (bound < rep_floor — a micro-population where
            // type_a already meets or exceeds the people). Seating even ONE
            // panel would put type_a + type_b OVER population, the exact thing
            // the cap forbids. So seat ZERO panels: the chamber lawfully has no
            // Type B seats (the ladder floors these to 0/1 for the same reason;
            // a sub-panel seat cannot be filled by a whole rep_floor panel).
            // Flag the undercount for the record — the cap is a HARD ceiling, so
            // undershooting is lawful where overshooting is not. DRIFT law: a
            // total that misses is a drawing defect here, never lawful over-seating.
            return self::result([], $repFloor, 0, $inert, $bound, true);
        }
        $p = min($pMax, $n);

        // Compact ordering of the constituents. The PATH is pure adjacency
        // (B5 — border_len weighted); only the STARTING end is chosen by
        // population, so the larger (remainder-bearing) panels fall on the
        // lowest-population constituents (B2). Population touches nothing else.
        $order = self::compactWalk($inhabited, $adjacency, $centroids);

        // Panel sizes: as equal as possible. `extra` panels take one more
        // constituent; those larger panels sit at the head of the order (the
        // lowest-population end) per B2.
        $base  = intdiv($n, $p);
        $extra = $n % $p;

        $panels = [];
        $cursor = 0;
        for ($i = 0; $i < $p; $i++) {
            $size = $base + ($i < $extra ? 1 : 0);
            $panels[] = array_slice($order, $cursor, $size);
            $cursor  += $size;
        }

        // The tight-bound (undercount) case early-returned above; a full grouping
        // is never an undercount.
        return self::result($panels, $repFloor, $p, $inert, $bound, false);
    }

    /**
     * A deterministic compact walk over the constituent adjacency graph.
     * Greedy nearest-neighbour by shared-border length (B5); when the current
     * node has no unvisited land neighbour (a disconnected / island component),
     * jump to the nearest unvisited constituent by centroid distance (B4), or —
     * with no centroid — the lowest-id unvisited node. The start is the
     * lowest-population constituent (ties by id) so the remainder lands low (B2).
     *
     * @param array<string,int>                    $populations
     * @param array<string,array<string,float>>    $adjacency
     * @param array<string,array{x:float,y:float}> $centroids
     * @return list<string>
     */
    private static function compactWalk(array $populations, array $adjacency, array $centroids): array
    {
        $ids = array_keys($populations);
        // Start at the lowest-population constituent (B2 orientation), ties by id.
        usort($ids, function (string $a, string $b) use ($populations): int {
            return [$populations[$a], $a] <=> [$populations[$b], $b];
        });
        $start = $ids[0];

        $visited = [];
        $order   = [];
        $cur     = $start;
        $visited[$cur] = true;
        $order[] = $cur;

        while (count($order) < count($populations)) {
            // The graph is restricted to inhabited constituents ($populations):
            // an inert (zero-pop) neighbour must never enter the walk.
            $next = self::bestNeighbour($cur, $adjacency, $visited, $populations);
            if ($next === null) {
                $next = self::nearestUnvisited($cur, $populations, $centroids, $visited);
            }
            $visited[$next] = true;
            $order[] = $next;
            $cur = $next;
        }

        return $order;
    }

    /**
     * Highest-border_len unvisited land neighbour (B5), ties by lowest id.
     *
     * @param array<string,array<string,float>> $adjacency
     * @param array<string,bool>                $visited
     * @param array<string,int>                 $inSet   inhabited constituents only
     */
    private static function bestNeighbour(string $cur, array $adjacency, array $visited, array $inSet): ?string
    {
        $best = null;
        $bestLen = -1.0;
        foreach ($adjacency[$cur] ?? [] as $nbr => $len) {
            $nbr = (string) $nbr;
            if (isset($visited[$nbr]) || ! isset($inSet[$nbr])) {
                continue;
            }
            $len = (float) $len;
            if ($len > $bestLen || ($len === $bestLen && ($best === null || $nbr < $best))) {
                $best = $nbr;
                $bestLen = $len;
            }
        }

        return $best;
    }

    /**
     * Nearest unvisited constituent by centroid distance (B4 island fallback);
     * distance ties — and the no-centroid case, one giant tie — break by
     * LOWEST POPULATION (B2), then lowest id (deterministic).
     *
     * @param array<string,int>                    $populations
     * @param array<string,array{x:float,y:float}> $centroids
     * @param array<string,bool>                   $visited
     */
    private static function nearestUnvisited(string $cur, array $populations, array $centroids, array $visited): string
    {
        $best = null;
        $bestD = INF;
        $haveCur = isset($centroids[$cur]);
        foreach (array_keys($populations) as $id) {
            $id = (string) $id;
            if (isset($visited[$id])) {
                continue;
            }
            if ($haveCur && isset($centroids[$id])) {
                $dx = $centroids[$cur]['x'] - $centroids[$id]['x'];
                $dy = $centroids[$cur]['y'] - $centroids[$id]['y'];
                $d  = $dx * $dx + $dy * $dy;
            } else {
                $d = INF; // no geometry to compare — population breaks the tie below
            }
            // Nearest distance first (compactness). On a distance TIE — and the
            // all-INF no-geometry case is one giant tie — fall back to POPULATION
            // so the remainder-bearing head of the walk stays lowest-population
            // (B2), then id for determinism. Ordering the tail by id (the old
            // behaviour) inverted B2 whenever id and population anti-correlate.
            $isBetter = false;
            if ($best === null || $d < $bestD) {
                $isBetter = true;
            } elseif ($d === $bestD) {
                $pi = $populations[$id];
                $pb = $populations[$best];
                $isBetter = $pi < $pb || ($pi === $pb && $id < $best);
            }
            if ($isBetter) {
                $best = $id;
                $bestD = $d;
            }
        }

        return (string) $best;
    }

    /**
     * @param list<list<string>> $panels
     * @param list<string>       $inert
     */
    private static function result(array $panels, int $repFloor, int $panelCount, array $inert, int $bound, bool $undercount): array
    {
        $groupSize = 0;
        foreach ($panels as $panel) {
            $groupSize = max($groupSize, count($panel));
        }

        return [
            'panels'        => $panels,
            'rep_floor'     => $repFloor,
            'panel_count'   => $panelCount,
            'seats'         => $panelCount * $repFloor,
            'inert'         => $inert,
            'group_size'    => $groupSize,
            'bound'         => $bound,
            'undercount'    => $undercount,
            'tie_break_key' => self::TIE_BREAK_KEY,
        ];
    }

    /**
     * Load one flagged legislature's constituent graph, compute the grouping,
     * persist the trio, recompute type_b_seats and CLEAR type_b_needs_districting.
     * Idempotent per (legislature, status): a fresh grouping archives the prior
     * active one. Returns a summary; null when the legislature is not groupable.
     *
     * @return array{legislature_id:string, grouping_id:string, panel_count:int, seats:int, undercount:bool}|null
     */
    public function apply(string $legislatureId, string $status = 'active', bool $dryRun = false): ?array
    {
        $leg = DB::table('legislatures')
            ->where('id', $legislatureId)
            ->whereNull('deleted_at')
            ->first(['id', 'jurisdiction_id', 'type_a_seats', 'type_b_rep_floor', 'total_seats']);
        if (! $leg) {
            return null;
        }

        $children = DB::table('jurisdictions')
            ->where('parent_id', $leg->jurisdiction_id)
            ->whereNull('deleted_at')
            ->get(['id', 'population']);
        if ($children->isEmpty()) {
            return null; // a leaf — no Type B (B6: its rep lives in the parent's chamber)
        }

        $populations = [];
        $popSum = 0;
        foreach ($children as $c) {
            $populations[(string) $c->id] = (int) $c->population;
            $popSum += max((int) $c->population, 0);
        }

        // B6: adjacency keyed on THIS parent only — never cross-parent.
        $adjacency = [];
        $edges = DB::table('jurisdiction_adjacency')
            ->where('parent_id', $leg->jurisdiction_id)
            ->where('dim', '>=', 1)
            ->get(['j1', 'j2', 'border_len']);
        foreach ($edges as $e) {
            $len = (float) $e->border_len;
            $adjacency[(string) $e->j1][(string) $e->j2] = $len;
            $adjacency[(string) $e->j2][(string) $e->j1] = $len;
        }

        $centroids = [];
        DB::table('jurisdiction_centroids')
            ->whereIn('jurisdiction_id', array_keys($populations))
            ->get(['jurisdiction_id', 'x', 'y'])
            ->each(function ($row) use (&$centroids) {
                $centroids[(string) $row->jurisdiction_id] = ['x' => (float) $row->x, 'y' => (float) $row->y];
            });

        $repFloor = (int) ($leg->type_b_rep_floor ?? TypeBSeatLadder::MIN_REP);

        $plan = self::computePanels($populations, $adjacency, (int) $leg->type_a_seats, $popSum, $repFloor, $centroids);

        if ($dryRun) {
            return [
                'legislature_id' => (string) $leg->id,
                'grouping_id'    => '(dry-run)',
                'panel_count'    => $plan['panel_count'],
                'seats'          => $plan['seats'],
                'undercount'     => $plan['undercount'],
            ];
        }

        return DB::transaction(function () use ($leg, $plan, $status, $popSum) {
            // B7: only one active grouping — archive any prior active plan.
            if ($status === 'active') {
                DB::table('legislature_type_b_groupings')
                    ->where('legislature_id', $leg->id)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->update(['status' => 'archived', 'effective_end' => now()->toDateString(), 'updated_at' => now()]);
            }

            $groupingId = (string) Str::uuid();
            DB::table('legislature_type_b_groupings')->insert([
                'id'              => $groupingId,
                'legislature_id'  => (string) $leg->id,
                'status'          => $status,
                'rep_floor'       => $plan['rep_floor'],
                'group_size'      => $plan['group_size'],
                'panel_count'     => $plan['panel_count'],
                'seats_total'     => $plan['seats'],
                'type_a_bound'    => (int) $leg->type_a_seats,
                'tie_break_key'   => $plan['tie_break_key'],
                'signature'       => self::signature($plan['panels']),
                'effective_start' => $status === 'active' ? now()->toDateString() : null,
                'notes'           => $plan['undercount'] ? 'population-capped undercount: bound below one full panel' : null,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            foreach ($plan['panels'] as $i => $members) {
                $panelId = (string) Str::uuid();
                DB::table('legislature_type_b_panels')->insert([
                    'id'             => $panelId,
                    'grouping_id'    => $groupingId,
                    'legislature_id' => (string) $leg->id,
                    'panel_number'   => $i + 1,
                    'seats'          => $plan['rep_floor'],
                    'member_count'   => count($members),
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
                $rows = [];
                foreach ($members as $jid) {
                    $rows[] = [
                        'id'              => (string) Str::uuid(),
                        'panel_id'        => $panelId,
                        'grouping_id'     => $groupingId,
                        'jurisdiction_id' => $jid,
                    ];
                }
                if ($rows !== []) {
                    DB::table('legislature_type_b_panel_jurisdictions')->insert($rows);
                }
            }

            // B7: only an ACTIVE plan takes effect THIS term. Recompute the
            // chamber and CLEAR the flag ONLY for an active grouping — the R-A
            // guard un-blocks the Type B race the instant type_b_needs_districting
            // is false. A DRAFT is a next-term plan: it persists as a grouping
            // (above) but MUST NOT resize or un-flag the sitting chamber — sitting
            // members serve out; the draft seats only when it is later activated.
            if ($status === 'active') {
                $totalSeats = (int) $leg->type_a_seats + $plan['seats'];
                DB::table('legislatures')->where('id', $leg->id)->update([
                    'type_b_seats'             => $plan['seats'],
                    'type_b_needs_districting' => false,
                    'total_seats'              => $totalSeats,
                    'quorum_required'          => max(3, (int) ceil($totalSeats / 2)),
                    'updated_at'               => now(),
                ]);
            }

            app(AuditService::class)->append(
                module: 'elections',
                event: 'type_b.grouping_applied',
                payload: [
                    'legislature_id' => (string) $leg->id,
                    'grouping_id'    => $groupingId,
                    'panel_count'    => $plan['panel_count'],
                    'rep_floor'      => $plan['rep_floor'],
                    'seats'          => $plan['seats'],
                    'type_a'         => (int) $leg->type_a_seats,
                    'population'     => $popSum,
                    'undercount'     => $plan['undercount'],
                    'tie_break'      => $plan['tie_break_key'],
                ],
            );

            return [
                'legislature_id' => (string) $leg->id,
                'grouping_id'    => $groupingId,
                'panel_count'    => $plan['panel_count'],
                'seats'          => $plan['seats'],
                'undercount'     => $plan['undercount'],
            ];
        });
    }

    /** A deterministic fingerprint of the membership for B7 change detection. */
    private static function signature(array $panels): string
    {
        $canon = array_map(function (array $members): array {
            sort($members);
            return $members;
        }, $panels);
        usort($canon, fn (array $a, array $b): int => ($a[0] ?? '') <=> ($b[0] ?? ''));

        return substr(hash('sha256', json_encode($canon)), 0, 64);
    }
}
