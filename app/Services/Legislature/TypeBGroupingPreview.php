<?php

namespace App\Services\Legislature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * TYPE B GROUPING PREVIEW — the read-only data core behind the Type B district
 * mapper VIEW (Phase 1, 2026-09-04). Given one flagged (or groupable) chamber,
 * it runs the PURE TypeBDistrictMapper::computePanels over the chamber's real
 * constituents and returns the panel assignment plus the three constitutional
 * stats the operator inspects: CONTIGUITY, POPULATION EQUALITY (intra-panel),
 * and SHAPE COMPACTNESS. It NEVER writes — the mapper's apply() persists; this
 * class only shows what apply() WOULD produce, so a map can be checked one at a
 * time before any run.
 *
 * The Type B map is FLAT (operator ruling 2026-09-04): one grouping of panels
 * over the parent's DIRECT children. There is no scope stepping — a clump is not
 * a composite. So this returns the whole chamber's grouping in one shot; the
 * clumping is cheap arithmetic, computed synchronously (no dispatch-and-poll,
 * unlike the Type A autoseed).
 */
class TypeBGroupingPreview
{
    /** Above this many constituents the live convex-hull compactness pass is skipped (ETL: bound the ST_Union). */
    public const COMPACTNESS_MAX_CHILDREN = 300;

    /** Intra-panel equality tiers (min_pop / max_pop within a clump). */
    public const EQ_GOOD = 0.70;
    public const EQ_OK   = 0.40;

    /** Convex-hull compactness tiers (mirror the Type A shape_compactness bands). */
    public const CHR_GOOD = 0.70;
    public const CHR_OK   = 0.50;

    /**
     * @return array{
     *   legislature: array<string,mixed>,
     *   meta: array<string,mixed>,
     *   children: list<array<string,mixed>>,
     *   panels: list<array<string,mixed>>,
     *   stats: array<string,mixed>
     * }|null  null when the id resolves to no legislature or a leaf (no children).
     *
     * @param string|null $groupingId When a persisted grouping id is given AND it
     *   resolves to this legislature, the STORED panel membership is rendered
     *   (its stats recomputed over that membership). Otherwise the canonical
     *   preview (what apply() would produce) is computed fresh. This lets the
     *   Type B mapper VIEW show a selected active/draft grouping, or a synthetic
     *   preview when none is persisted.
     */
    public function forLegislature(string $legId, ?string $groupingId = null): ?array
    {
        $leg = DB::table('legislatures')
            ->where('id', $legId)
            ->whereNull('deleted_at')
            ->first(['id', 'jurisdiction_id', 'type_a_seats', 'type_b_seats', 'type_b_rep_floor', 'type_b_needs_districting', 'total_seats']);
        if (! $leg) {
            return null;
        }

        $parent = DB::table('jurisdictions')
            ->where('id', $leg->jurisdiction_id)
            ->first(['id', 'name', 'slug', 'adm_level']);
        if (! $parent) {
            return null;
        }

        $childRows = DB::table('jurisdictions')
            ->where('parent_id', $leg->jurisdiction_id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'adm_level', 'population']);
        if ($childRows->isEmpty()) {
            return null; // a leaf — no Type B here (its rep lives in the parent's chamber)
        }

        $populations = [];
        $popSum = 0;
        foreach ($childRows as $c) {
            $populations[(string) $c->id] = (int) $c->population;
            $popSum += max((int) $c->population, 0);
        }

        // B6: adjacency keyed on THIS parent only — never cross-parent.
        $adjacency = [];
        foreach (DB::table('jurisdiction_adjacency')
            ->where('parent_id', $leg->jurisdiction_id)
            ->where('dim', '>=', 1)
            ->get(['j1', 'j2', 'border_len']) as $e) {
            $len = (float) $e->border_len;
            $adjacency[(string) $e->j1][(string) $e->j2] = $len;
            $adjacency[(string) $e->j2][(string) $e->j1] = $len;
        }
        $hasAdjacency = $adjacency !== [];

        $centroids = [];
        DB::table('jurisdiction_centroids')
            ->whereIn('jurisdiction_id', array_keys($populations))
            ->get(['jurisdiction_id', 'x', 'y'])
            ->each(function ($row) use (&$centroids) {
                $centroids[(string) $row->jurisdiction_id] = ['x' => (float) $row->x, 'y' => (float) $row->y];
            });

        $repFloor = max(TypeBSeatLadder::MIN_REP, (int) ($leg->type_b_rep_floor ?? TypeBSeatLadder::MIN_REP));

        // A persisted grouping (active/draft) is rendered from its STORED
        // membership so the VIEW reflects exactly what is seated / drafted.
        // storedPlan returns null when the id does not resolve to this
        // legislature. With NO resolvable grouping the map is BLANK — no panels,
        // every constituent unassigned — mirroring the Type A no-map state. The
        // auto-clumping is produced only by Autoseed (apply()), never synthesised
        // as a phantom default (that phantom was the "a map exists but also
        // doesn't" bug, operator report 2026-09-05).
        $plan = null;
        if ($groupingId !== null) {
            $plan = $this->storedPlan((string) $leg->id, $groupingId, $populations, $repFloor, (int) $leg->type_a_seats, $popSum);
        }
        if ($plan === null) {
            $plan = [
                'panels'        => [],
                'panel_seats'   => [],
                'rep_floor'     => $repFloor,
                'panel_count'   => 0,
                'seats'         => 0,
                'inert'         => [],
                'group_size'    => 0,
                'bound'         => min((int) $leg->type_a_seats, max(0, $popSum - (int) $leg->type_a_seats)),
                'undercount'    => false,
                'tie_break_key' => TypeBDistrictMapper::TIE_BREAK_KEY,
            ];
        }

        // Map each constituent to its panel number (1-based). EVERY member is in a
        // panel now (zero-population parts included, B3 2026-09-05); the `inert`
        // list flags which have no residents so the VIEW can render them as
        // territory (zero representation weight) inside the clump they vote with.
        $panelOf = [];
        foreach ($plan['panels'] as $i => $members) {
            foreach ($members as $mid) {
                $panelOf[(string) $mid] = $i + 1;
            }
        }
        $panelSeats = $plan['panel_seats'] ?? [];
        $inert = array_flip(array_map('strval', $plan['inert']));

        $children = [];
        foreach ($childRows as $c) {
            $id = (string) $c->id;
            $children[] = [
                'id'         => $id,
                'name'       => $c->name,
                'slug'       => $c->slug,
                'adm_level'  => (int) $c->adm_level,
                'population' => (int) $c->population,
                'panel'      => $panelOf[$id] ?? null,
                'inert'      => isset($inert[$id]),
            ];
        }

        // Per-panel roll-up. member_count is the WHOLE clump (zeros included);
        // seats is the panel's own count (rep_floor, or rep_floor+bonus for the
        // one bonus panel). WEIGHT = seats / member_count is the equality the
        // model now holds uniform (operator ruling 2026-09-05). The intra-panel
        // population equality is kept as an INFORMATIONAL metric only — computed
        // over inhabited members (zeros excluded so territory does not read as
        // lopsided) — because STV absorbs residual lopsidedness; it no longer
        // drives the grouping.
        $panels = [];
        foreach ($plan['panels'] as $i => $members) {
            $inhabitedPops = [];
            foreach ($members as $m) {
                $pop = (int) ($populations[(string) $m] ?? 0);
                if ($pop > 0) {
                    $inhabitedPops[] = $pop;
                }
            }
            $panelPop = array_sum($inhabitedPops);
            $maxPop   = $inhabitedPops === [] ? 0 : max($inhabitedPops);
            $minPop   = $inhabitedPops === [] ? 0 : min($inhabitedPops);
            $count    = count($members);
            $seats    = (int) ($panelSeats[$i] ?? $repFloor);
            $bonus    = max(0, $seats - $repFloor);
            $equality = count($inhabitedPops) <= 1 ? 1.0 : ($maxPop > 0 ? round($minPop / $maxPop, 4) : 1.0);
            // Dominance among inhabited members: one member could carry the whole
            // clump's shared at-large race. Informational — STV handles it.
            $dominated = count($inhabitedPops) >= 2 && $maxPop * 2 > $panelPop;

            $panels[] = [
                'panel_number'      => $i + 1,
                'member_count'      => $count,
                'seats'             => $seats,
                'bonus_seats'       => $bonus,
                'weight'            => $count > 0 ? round($seats / $count, 4) : 0.0,
                'population'        => $panelPop,
                'min_pop'          => $minPop,
                'max_pop'          => $maxPop,
                'internal_equality' => $equality,
                'dominated'        => $dominated,
                'members'          => array_map('strval', $members),
            ];
        }

        $stats = [
            'contiguity'          => $this->contiguityStats($plan['panels'], $adjacency, $hasAdjacency, $populations),
            'population_equality' => $this->equalityStats($panels),
            'shape_compactness'   => $this->compactnessStats((string) $leg->id, $plan['panels'], count($childRows), $populations),
        ];

        // Attach the per-panel contiguity / compactness back onto each panel row
        // for the legend (best-effort; the aggregate stats above are the headline).
        foreach ($stats['contiguity']['per_panel'] as $pn => $flag) {
            if (isset($panels[$pn - 1])) {
                $panels[$pn - 1]['contiguous'] = $flag;
            }
        }
        foreach ($stats['shape_compactness']['per_panel'] as $pn => $chr) {
            if (isset($panels[$pn - 1])) {
                $panels[$pn - 1]['compactness'] = $chr;
            }
        }

        $activeGrouping = DB::table('legislature_type_b_groupings')
            ->where('legislature_id', $leg->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();

        return [
            'legislature' => [
                'id'                       => (string) $leg->id,
                'jurisdiction_id'          => (string) $leg->jurisdiction_id,
                'slug'                     => $parent->slug,
                'name'                     => $parent->name,
                'adm_level'                => (int) $parent->adm_level,
                'type_a_seats'             => (int) $leg->type_a_seats,
                'type_b_seats'             => (int) $leg->type_b_seats,
                'type_b_rep_floor'         => $repFloor,
                'type_b_needs_districting'  => (bool) $leg->type_b_needs_districting,
                'total_seats'              => (int) $leg->total_seats,
                'has_active_grouping'      => $activeGrouping,
            ],
            'meta' => [
                'n_children'   => count($childRows),
                'n_inhabited'  => count($childRows) - count($plan['inert']),
                'n_inert'      => count($plan['inert']),
                'pop_sum'      => $popSum,
                'bound'        => $plan['bound'],
                'panel_count'  => $plan['panel_count'],
                'group_size'   => $plan['group_size'],
                'seats'        => $plan['seats'],
                'undercount'   => $plan['undercount'],
                'has_adjacency' => $hasAdjacency,
            ],
            'children' => $children,
            'panels'   => $panels,
            'stats'    => $stats,
        ];
    }

    /**
     * Rebuild the computePanels-shaped plan from a PERSISTED grouping's stored
     * membership (panels ordered by panel_number). EVERY stored member is a panel
     * member (zero-population parts included, B3 2026-09-05); the `inert` list
     * flags which have no residents (territory, zero weight — they still vote with
     * their clump when someone lives there). Each panel's own seat count is read
     * from the table (rep_floor, or rep_floor+bonus for the bonus panel). Returns
     * null when the id does not resolve to a non-deleted grouping for this
     * legislature, so the caller falls back to the blank (no-map) state.
     *
     * @param array<string,int> $populations constituent id => population
     * @return array{
     *   panels: list<list<string>>, panel_seats: list<int>, rep_floor:int,
     *   panel_count:int, seats:int, inert: list<string>, group_size:int,
     *   bound:int, undercount:bool
     * }|null
     */
    private function storedPlan(string $legId, string $groupingId, array $populations, int $repFloor, int $typeA, int $population): ?array
    {
        $grouping = DB::table('legislature_type_b_groupings')
            ->where('id', $groupingId)
            ->where('legislature_id', $legId)
            ->whereNull('deleted_at')
            ->first(['id', 'rep_floor', 'notes']);
        if (! $grouping) {
            return null;
        }

        $panelRows = DB::table('legislature_type_b_panels')
            ->where('grouping_id', $groupingId)
            ->whereNull('deleted_at')
            ->orderBy('panel_number')
            ->get(['id', 'panel_number', 'seats']);
        if ($panelRows->isEmpty()) {
            // A grouping with zero panels (lawful inactive / undercount) — an
            // empty plan renders as no districts, closed.
            return [
                'panels' => [], 'panel_seats' => [], 'rep_floor' => (int) $grouping->rep_floor, 'panel_count' => 0,
                'seats' => 0, 'inert' => [], 'group_size' => 0,
                'bound' => min($typeA, max(0, $population - $typeA)), 'undercount' => (bool) $grouping->notes,
            ];
        }

        // Panel number → its stored jurisdiction ids.
        $memberRows = DB::table('legislature_type_b_panel_jurisdictions as pj')
            ->join('legislature_type_b_panels as p', 'p.id', '=', 'pj.panel_id')
            ->where('pj.grouping_id', $groupingId)
            ->whereNull('p.deleted_at')
            ->get(['p.panel_number', 'pj.jurisdiction_id']);
        $byPanel = [];
        foreach ($memberRows as $r) {
            $byPanel[(int) $r->panel_number][] = (string) $r->jurisdiction_id;
        }

        $panels     = [];
        $panelSeats = [];
        $inert      = [];
        $groupSize  = 0;
        $seatsTotal = 0;
        foreach ($panelRows as $p) {
            $pn      = (int) $p->panel_number;
            $members = $byPanel[$pn] ?? [];
            foreach ($members as $jid) {
                if ((int) ($populations[$jid] ?? 0) <= 0) {
                    $inert[] = $jid;
                }
            }
            $panels[]     = $members;
            $panelSeats[] = (int) $p->seats;
            $seatsTotal  += (int) $p->seats;
            $groupSize    = max($groupSize, count($members));
        }
        sort($inert);

        return [
            'panels'      => $panels,
            'panel_seats' => $panelSeats,
            'rep_floor'   => (int) $grouping->rep_floor,
            'panel_count' => count($panels),
            'seats'       => $seatsTotal,
            'inert'       => $inert,
            'group_size'  => $groupSize,
            'bound'       => min($typeA, max(0, $population - $typeA)),
            'undercount'  => (bool) $grouping->notes,
        ];
    }

    /**
     * Contiguity per panel: is the panel's membership one connected component in
     * the constituent adjacency graph? Single-member = trivially contiguous. When
     * the parent has NO adjacency data at all, contiguity is not computable.
     *
     * @param list<list<string>>                 $panels
     * @param array<string,array<string,float>>  $adjacency
     * @param array<string,int>                  $populations
     */
    private function contiguityStats(array $panels, array $adjacency, bool $hasAdjacency, array $populations): array
    {
        $contiguous = 0; $nonContiguous = 0; $unchecked = 0;
        $contigPop = 0; $nonContigPop = 0; $uncheckedPop = 0;
        $perPanel = [];

        foreach ($panels as $i => $members) {
            $pn = $i + 1;
            $panelPop = 0;
            foreach ($members as $m) {
                $panelPop += max(0, (int) ($populations[(string) $m] ?? 0));
            }

            if (count($members) <= 1) {
                $perPanel[$pn] = true;
                $contiguous++; $contigPop += $panelPop;
                continue;
            }
            if (! $hasAdjacency) {
                $perPanel[$pn] = null;
                $unchecked++; $uncheckedPop += $panelPop;
                continue;
            }

            $connected = $this->isConnected($members, $adjacency);
            $perPanel[$pn] = $connected;
            if ($connected) { $contiguous++; $contigPop += $panelPop; }
            else            { $nonContiguous++; $nonContigPop += $panelPop; }
        }

        return [
            'contiguous_count'     => $contiguous,
            'non_contiguous_count' => $nonContiguous,
            'unchecked_count'      => $unchecked,
            'checked_count'        => $contiguous + $nonContiguous,
            'contiguous_pop'       => $contigPop,
            'non_contiguous_pop'   => $nonContigPop,
            'unchecked_pop'        => $uncheckedPop,
            'per_panel'            => $perPanel,
        ];
    }

    /**
     * @param list<string>                       $members
     * @param array<string,array<string,float>>  $adjacency
     */
    private function isConnected(array $members, array $adjacency): bool
    {
        $set = array_flip(array_map('strval', $members));
        $start = (string) $members[0];
        $seen = [$start => true];
        $stack = [$start];
        while ($stack !== []) {
            $cur = array_pop($stack);
            foreach ($adjacency[$cur] ?? [] as $nbr => $_) {
                $nbr = (string) $nbr;
                if (isset($set[$nbr]) && ! isset($seen[$nbr])) {
                    $seen[$nbr] = true;
                    $stack[] = $nbr;
                }
            }
        }

        return count($seen) === count($set);
    }

    /**
     * Intra-panel population equality — the 2026-09-04 ruling's headline. A
     * clump whose members are lopsided in population lets one member overwhelm
     * the others in the shared at-large race, undoing the counterbalance. Tiers
     * by min_pop / max_pop; dominated_count is the sharp failure (one member >
     * the sum of the rest).
     *
     * @param list<array<string,mixed>> $panels
     */
    private function equalityStats(array $panels): array
    {
        $tiers = [
            'good' => ['count' => 0, 'population' => 0],
            'ok'   => ['count' => 0, 'population' => 0],
            'bad'  => ['count' => 0, 'population' => 0],
        ];
        $dominated = 0;
        $sumEq = 0.0;
        $worst = null;

        foreach ($panels as $p) {
            $eq = (float) $p['internal_equality'];
            $sumEq += $eq;
            $band = $eq >= self::EQ_GOOD ? 'good' : ($eq >= self::EQ_OK ? 'ok' : 'bad');
            $tiers[$band]['count']++;
            $tiers[$band]['population'] += (int) $p['population'];
            if ($p['dominated']) {
                $dominated++;
            }
            if ($worst === null || $eq < $worst['internal_equality']) {
                $worst = ['panel_number' => $p['panel_number'], 'internal_equality' => $eq, 'min_pop' => $p['min_pop'], 'max_pop' => $p['max_pop']];
            }
        }

        $n = count($panels);
        foreach ($tiers as $k => $t) {
            $tiers[$k]['pct'] = $n > 0 ? round($t['count'] * 100 / $n, 1) : 0.0;
        }

        return [
            'panel_count'           => $n,
            'dominated_count'       => $dominated,
            'mean_internal_equality' => $n > 0 ? round($sumEq / $n, 4) : 1.0,
            'tiers'                 => $tiers,
            'worst_panel'           => $worst,
        ];
    }

    /**
     * Shape compactness per panel: convex-hull ratio (area of the panel's
     * unioned geometry over the area of its convex hull), mirroring the Type A
     * shape_compactness bands. Live PostGIS, bounded to one chamber and skipped
     * above COMPACTNESS_MAX_CHILDREN (ETL: never an unbounded ST_Union). Cached
     * forever by the grouping signature — the preview is deterministic.
     *
     * @param list<list<string>> $panels
     * @param array<string,int>  $populations constituent id => population
     */
    private function compactnessStats(string $legId, array $panels, int $childCount, array $populations = []): array
    {
        // Tiers carry count + population + pct to mirror the Type A
        // shape_compactness shape the mapper sidebar reads.
        $tiers = [
            'good' => ['count' => 0, 'population' => 0],
            'ok'   => ['count' => 0, 'population' => 0],
            'bad'  => ['count' => 0, 'population' => 0],
        ];
        $perPanel = [];

        // Panel (0-based index) => summed inhabited population, for tier pops.
        $panelPop = static function (array $members) use ($populations): int {
            $sum = 0;
            foreach ($members as $m) {
                $sum += max(0, (int) ($populations[(string) $m] ?? 0));
            }

            return $sum;
        };

        $multi = array_filter($panels, fn ($p) => count($p) >= 2);
        if ($multi === [] || $childCount > self::COMPACTNESS_MAX_CHILDREN) {
            return [
                'mean'            => null,
                'tiers'           => $tiers,
                'checked_count'   => 0,
                'unchecked_count' => count($multi),
                'total_population' => 0,
                'skipped'         => $childCount > self::COMPACTNESS_MAX_CHILDREN,
                'per_panel'       => $perPanel,
            ];
        }

        $sig = substr(hash('sha256', json_encode(array_map(function (array $p) {
            sort($p);
            return $p;
        }, $panels))), 0, 32);

        $chrByPanel = Cache::rememberForever("typeb.compactness.{$legId}.{$sig}", function () use ($panels) {
            $ids = [];
            $pn = [];
            foreach ($panels as $i => $members) {
                if (count($members) < 2) {
                    continue;
                }
                foreach ($members as $m) {
                    $ids[] = (string) $m;
                    $pn[] = $i + 1;
                }
            }
            if ($ids === []) {
                return [];
            }
            $idArr = '{' . implode(',', $ids) . '}';
            $pnArr = '{' . implode(',', $pn) . '}';

            $rows = DB::select('
                SELECT m.panel AS panel,
                       ST_Area(ST_Union(j.geom)) / NULLIF(ST_Area(ST_ConvexHull(ST_Collect(j.geom))), 0) AS chr
                FROM unnest(?::uuid[], ?::int[]) AS m(jid, panel)
                JOIN jurisdictions j ON j.id = m.jid AND j.geom IS NOT NULL
                GROUP BY m.panel
            ', [$idArr, $pnArr]);

            $out = [];
            foreach ($rows as $r) {
                $out[(int) $r->panel] = $r->chr === null ? null : round((float) $r->chr, 4);
            }

            return $out;
        });

        // $multi preserves the original 0-based panel index as its key; the
        // panel number the legend keys on is index + 1.
        $sum = 0.0; $checked = 0; $checkedPop = 0;
        foreach ($multi as $i => $members) {
            $pn = $i + 1;
            $chr = $chrByPanel[$pn] ?? null;
            $perPanel[$pn] = $chr;
            if ($chr === null) {
                continue;
            }
            $checked++;
            $sum += $chr;
            $pop = $panelPop($members);
            $checkedPop += $pop;
            $band = $chr >= self::CHR_GOOD ? 'good' : ($chr >= self::CHR_OK ? 'ok' : 'bad');
            $tiers[$band]['count']++;
            $tiers[$band]['population'] += $pop;
        }
        foreach ($tiers as $k => $t) {
            $tiers[$k]['pct'] = $checked > 0 ? round($t['count'] * 100 / $checked, 1) : 0.0;
        }

        return [
            'mean'            => $checked > 0 ? round($sum / $checked, 4) : null,
            'tiers'           => $tiers,
            'checked_count'   => $checked,
            'unchecked_count' => count($multi) - $checked,
            'total_population' => $checkedPop,
            'skipped'         => false,
            'per_panel'       => $perPanel,
        ];
    }
}
