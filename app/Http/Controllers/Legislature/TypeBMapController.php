<?php

namespace App\Http\Controllers\Legislature;

use App\Http\Controllers\Controller;
use App\Services\Legislature\TypeBDistrictMapper;
use App\Services\Legislature\TypeBGroupingPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * THE TYPE B DISTRICT MAPPER VIEW — the operator's one-map-at-a-time inspection
 * and drafting surface for Type B clumping. It reuses the Type A district-mapper
 * Vue component (resources/js/Pages/Legislature/TypeBDistricts.vue, a verbatim
 * clone of Districts.vue) by BUILDING the exact same Inertia prop contract from
 * Type B grouping data.
 *
 * THE MODEL MAPPING (Type B grouping -> Type A district-map prop contract):
 *   - A PANEL is a "district"; each district id is a stable synthetic string
 *     "{groupingId|'preview'}:{panelNumber}".
 *   - A CONSTITUENT (direct child jurisdiction) is a "child" assigned to its
 *     panel's district.
 *   - A GROUPING (draft/active/archived) is a "map".
 * Type B is FLAT: no scope drilling, no giants, no leaf-giant line-draw. So
 * every child carries is_giant=false / child_count=0, ancestors is just the
 * chamber jurisdiction, and can_draw / map_drawable / setup_mode are false.
 *
 * The route param accepts the chamber's jurisdiction slug / UUID, or the
 * legislature UUID (parity with the Type A dual-accept).
 */
class TypeBMapController extends Controller
{
    public function __construct(private TypeBGroupingPreview $preview)
    {
    }

    /** Constitutional thresholds surfaced to the cloned component (5/9 defaults). */
    private const CONSTITUTIONAL = [
        'floor'             => 5,
        'ceiling'           => 9,
        'giant_threshold'   => 9.5,
        'floor_boundary'    => 5.0,
        'floor_override'    => 4.5,
        'autoseed_template' => 'default',
    ];

    public function show(Request $request, string $legislature_id)
    {
        $legId = $this->resolveLegislatureId($legislature_id);
        abort_unless($legId !== null, 404, 'No legislature found for that identifier.');

        $props = $this->buildProps($legId, $request->query('map'));
        abort_unless($props !== null, 404, 'This jurisdiction has no Type B chamber — a leaf has no constituents to group.');

        return Inertia::render('Legislature/TypeBDistricts', $props);
    }

    /**
     * The revealed (giant-breakdown) geometry layer. Type B is flat and has no
     * giants, so it is always empty — but the cloned component fetches it on
     * mount, and the Type A endpoint 500s on the synthetic 'preview' map id. This
     * returns an empty FeatureCollection so the constituent layer renders.
     */
    public function revealedGeoJson(): JsonResponse
    {
        return response()->json(['type' => 'FeatureCollection', 'features' => []]);
    }

    /**
     * Build the full Type A prop contract from Type B data for the resolved
     * legislature and its selected grouping (explicit ?map -> active -> newest
     * draft -> synthetic in-memory preview when none is persisted).
     *
     * @return array<string,mixed>|null null when the id is a leaf (no children).
     */
    private function buildProps(string $legId, ?string $requestedMapId): ?array
    {
        $groupings = $this->groupingRows($legId);

        // Which grouping to render: explicit request, else active, else newest draft.
        $selected = null;
        if ($requestedMapId !== null && $requestedMapId !== '' && $requestedMapId !== 'preview') {
            $selected = $groupings->firstWhere('id', $requestedMapId);
        }
        if ($selected === null) {
            $selected = $groupings->firstWhere('status', 'active')
                ?? $groupings->firstWhere('status', 'draft');
        }
        $selectedId = $selected?->id;

        $data = $this->preview->forLegislature($legId, $selectedId);
        if ($data === null) {
            return null;
        }

        $leg  = $data['legislature'];
        $meta = $data['meta'];
        $jid  = $leg['jurisdiction_id'];

        // Chamber jurisdiction: own population row + bbox for the Leaflet fit.
        $jrow = DB::table('jurisdictions')->where('id', $jid)->first(['population']);
        $bbox = DB::selectOne('
            SELECT ST_YMin(geom) AS south, ST_XMin(geom) AS west,
                   ST_YMax(geom) AS north, ST_XMax(geom) AS east
            FROM jurisdictions WHERE id = ?
        ', [$jid]);
        $status = (string) DB::table('legislatures')->where('id', $legId)->value('status');
        $chamberSeated = DB::table('legislature_members')
            ->where('legislature_id', $legId)
            ->whereIn('status', ['elected', 'seated'])
            ->whereNull('deleted_at')
            ->exists();

        $repFloor = (int) $leg['type_b_rep_floor'];
        $groupKey = $selectedId ?? 'preview';
        $seats    = (int) $meta['seats'];
        $popSum   = (int) $meta['pop_sum'];
        $quota    = (int) round($popSum / max(1, $seats));

        // Type B "seats to assign" is the seat BUDGET — the min of the Type A cap
        // and what population leaves — a stable divisor that sizes the clumps,
        // NOT the transient current-seats (which was showing as an assigned count
        // that fell to 0 on Clear). Operator ruling 2026-09-05.
        $budget = min((int) $leg['type_a_seats'], max(0, $popSum - (int) $leg['type_a_seats']));

        // Each constituent's representation WEIGHT = its panel's seats / member
        // count (operator ruling 2026-09-05: members-per-seat, held uniform across
        // the map so equal clumps read equal). A zero-population part is territory
        // — weight 0. panelSeatsOf carries each panel's OWN seat count (rep_floor,
        // or rep_floor+bonus for the one bonus panel) for district_seats.
        $weightOf     = [];
        $panelSeatsOf = [];
        foreach ($data['panels'] as $p) {
            $sz    = (int) $p['member_count'];
            $seats = (int) $p['seats'];
            $w     = $sz > 0 ? round($seats / $sz, 4) : 0.0;
            foreach ($p['members'] as $mid) {
                $weightOf[(string) $mid]     = $w;
                $panelSeatsOf[(string) $mid] = $seats;
            }
        }
        $fracOf = function (string $id, int $pop) use ($weightOf): float {
            return $pop > 0 ? ($weightOf[$id] ?? 0.0) : 0.0;
        };

        // Constituent id -> row, for member names inside districts[].
        $childById = [];
        foreach ($data['children'] as $c) {
            $childById[$c['id']] = $c;
        }

        // children[]: each constituent is a flat, never-drillable "child".
        $children = array_map(function (array $c) use ($groupKey, $repFloor, $fracOf, $panelSeatsOf): array {
            $pn = $c['panel'];

            return [
                'id'                   => $c['id'],
                'name'                 => $c['name'],
                'slug'                 => $c['slug'],
                'adm_level'            => $c['adm_level'],
                'population'           => $c['population'],
                'fractional_seats'     => $fracOf($c['id'], (int) $c['population']),   // representation weight, 0 if unassigned/zero-pop
                'is_giant'             => false,
                'cascade_seats'        => null,
                'district_id'          => $pn !== null ? "{$groupKey}:{$pn}" : null,
                'district_seats'       => $pn !== null ? ($panelSeatsOf[$c['id']] ?? $repFloor) : null,
                'floor_override'       => false,
                'child_count'          => 0,     // never offer a drill in a flat map
                'child_assigned_seats' => 0,
                'child_bonus_seats'    => 0,
                'type_a_apportioned'   => null,
                'drawn_seats'          => 0,
            ];
        }, $data['children']);

        // Collision-free panel colours: greedy adjacency 7-colouring over the
        // panel graph (two panels adjacent when their members share a border),
        // the SAME algorithm Type A uses (LegislatureController::colorIndicesForDistricts).
        // A naive (pn-1)%7 let neighbouring clumps share a colour.
        $colorByPanel = $this->panelColorIndices($data['panels'], $jid);

        // districts[]: each panel is a "district". method is OMITTED so the
        // component treats a panel as a composite district — counted in the
        // seat budget and editable in the list (a drawn/method='drawn' row is
        // excluded from seatCountableDistricts and shown read-only).
        $districts = array_map(function (array $p) use ($groupKey, $repFloor, $childById, $leg, $status, $fracOf, $colorByPanel): array {
            $pn = (int) $p['panel_number'];
            $members = array_map(function ($mid) use ($childById, $fracOf): array {
                $mc = $childById[(string) $mid] ?? null;

                return [
                    'id'               => (string) $mid,
                    'name'             => $mc['name'] ?? '',
                    'population'       => (int) ($mc['population'] ?? 0),
                    'fractional_seats' => $fracOf((string) $mid, (int) ($mc['population'] ?? 0)),
                    'child_count'      => 0,
                ];
            }, $p['members']);

            return [
                'id'                => "{$groupKey}:{$pn}",
                'seats'             => (int) $p['seats'],
                'bonus_seats'       => (int) ($p['bonus_seats'] ?? 0),
                'floor_override'    => false,
                'status'            => $status,
                'color_index'       => $colorByPanel[$pn] ?? (($pn - 1) % 7),
                'district_number'   => $pn,
                'name'              => 'Panel ' . $pn,
                'population'        => (int) $p['population'],
                // Equal seats read as on-target (frac/seats - 1 = 0) in the strip.
                'fractional_seats'  => (float) $p['seats'],
                'convex_hull_ratio' => $p['compactness'] ?? null,
                'is_contiguous'     => $p['contiguous'] ?? null,
                'has_integrity'     => true,
                'scope_iso'         => null,
                'scope_adm'         => (int) $leg['adm_level'],
                'scope_name'        => 'Panel ' . $pn,
                'deviation_pct'     => null,
                'members'           => $members,
                'centroid'          => null,
            ];
        }, $data['panels']);

        // maps[]: the persisted groupings.
        $maps = $groupings->map(fn ($g) => [
            'id'              => (string) $g->id,
            'name'            => $this->mapName($g),
            'status'          => $g->status,
            'effective_start' => $g->effective_start,
            'effective_end'   => $g->effective_end,
            'district_count'  => (int) $g->panel_count,
            'flags'           => null,
            'total_flags'     => null,
        ])->values()->all();

        // active_map: the selected grouping, or NULL when the chamber has no
        // grouping (a blank map — no synthetic preview). `editable` gates the
        // hand-edit affordances to a draft (an active/seated plan is read-only).
        $activeMap = $selected !== null
            ? [
                'id'       => (string) $selected->id,
                'name'     => $this->mapName($selected),
                'status'   => $selected->status,
                'editable' => $selected->status === 'draft',
              ]
            : null;

        // ── BAD-MAP FLAGS (operator 2026-09-05): hard, avoidable errors on a
        // hand-edited map are surfaced as red flags in Map Quality.
        $panelSeatsSum = 0;
        $memberCounts  = [];
        foreach ($data['panels'] as $p) {
            $panelSeatsSum += (int) $p['seats'];
            $memberCounts[] = (int) $p['member_count'];
        }
        $unassignedCount = 0;
        foreach ($data['children'] as $c) {
            if (($c['panel'] ?? null) === null) {
                $unassignedCount++;
            }
        }
        $flags = [
            'cap'                => null,
            'floor_exceptions'   => [],
            'ceiling_exceptions' => [],
            'deep_overages'      => [],
            'incomplete_scopes'  => [],
            'uneven_clumps'      => null,
        ];
        // 1) Seat-count breach — too many panels push Σ seats over the ceiling.
        if ($panelSeatsSum > $budget) {
            $flags['cap'] = ['delta' => $panelSeatsSum - $budget, 'total' => $panelSeatsSum, 'max' => $budget];
        }
        // 2) Unassigned constituents — parts in no panel.
        if ($unassignedCount > 0) {
            $flags['incomplete_scopes'] = [[
                'scope_id' => $jid, 'scope_name' => $leg['name'], 'unassigned_count' => $unassignedCount,
            ]];
        }
        // 3) Uneven clumps — member spread beyond the forced base/base+1 (an even
        //    split has max − min ≤ 1; more is a hand-made imbalance).
        if ($memberCounts !== []) {
            $mn = min($memberCounts);
            $mx = max($memberCounts);
            if ($mx - $mn > 1) {
                $flags['uneven_clumps'] = ['min' => $mn, 'max' => $mx, 'spread' => $mx - $mn, 'panel_count' => count($memberCounts)];
            }
        }

        // The map is called PANELS here (not Districts) — override the shared
        // surface title so the tab / any chrome reads Type B's own term.
        $surface = \App\Support\SurfaceMeta::for('legislature/districts');
        $surface['title'] = 'Type B Panels';

        return [
            'surface' => $surface,
            'legislature' => [
                'id'                   => $leg['id'],
                'slug'                 => $leg['slug'],
                // Flat map: root == chamber jurisdiction, so mapUrl never adds a
                // ?scope and no drill is ever synthesised.
                'root_jurisdiction_id' => $jid,
                'type_a_seats'         => (int) $leg['type_a_seats'],
                'type_b_seats'         => (int) $leg['type_b_seats'],
                'status'               => $status,
                'chamber_seated'       => $chamberSeated,
            ],
            'scope' => [
                'id'         => $jid,
                'name'       => $leg['name'],
                'slug'       => $leg['slug'],
                'adm_level'  => (int) $leg['adm_level'],
                'population' => (int) ($jrow->population ?? $popSum),
                'bbox'       => $bbox
                    ? [(float) $bbox->south, (float) $bbox->west, (float) $bbox->north, (float) $bbox->east]
                    : null,
            ],
            'scope_seats' => $budget,
            'ancestors'   => [[
                'id'   => $jid,
                'name' => $leg['name'],
                'slug' => $leg['slug'],
            ]],
            'children'  => $children,
            'districts' => $districts,
            'quota'     => $quota,
            'flags'     => $flags,
            'stats'             => $this->mapStats($data, $groupKey, $popSum),
            'mass_tool_running' => false,
            'maps'              => $maps,
            'active_map'        => $activeMap,
            'setup_mode'        => false,
            'can_draw'          => false,
            'map_drawable'      => false,
            'constitutional'    => self::CONSTITUTIONAL,
        ];
    }

    /**
     * Reshape the preview stats (contiguity / population_equality /
     * shape_compactness) into the EXACT computeConstitutionalStats() shape the
     * cloned component reads, and synthesise community_integrity (Type B never
     * cuts geometry, so integrity is total).
     *
     * @param array<string,mixed> $data preview payload
     */
    private function mapStats(array $data, string $groupKey, int $popSum): array
    {
        $s = $data['stats'];
        $eq = $s['population_equality'];
        $panels = $data['panels'];
        $panelCount = count($panels);

        // population_equality: keep the preview tiers; synthesise the Type A
        // scalars from mean/worst equality. avg/max deviation = (1 - equality).
        $mean  = (float) ($eq['mean_internal_equality'] ?? 1.0);
        $worst = $eq['worst_panel'] ?? null;

        // most_over = the best (highest equality) panel; most_under = the worst.
        $best = null;
        foreach ($panels as $p) {
            $e = (float) $p['internal_equality'];
            if ($best === null || $e > $best['internal_equality']) {
                $best = ['panel_number' => $p['panel_number'], 'internal_equality' => $e];
            }
        }
        $overPn  = $best['panel_number'] ?? ($worst['panel_number'] ?? 1);
        $underPn = $worst['panel_number'] ?? ($best['panel_number'] ?? 1);
        $overDev  = round((1 - (float) ($best['internal_equality'] ?? 1.0)) * 100, 2);
        $underDev = round((1 - (float) ($worst['internal_equality'] ?? 1.0)) * 100, 2);

        $populationEquality = [
            'district_count'    => $panelCount,
            'max_deviation_pct' => $underDev,
            'avg_deviation_pct' => round((1 - $mean) * 100, 2),
            'range_ratio'       => round(1 / max($mean, 1e-9), 3),
            'most_over'  => $panelCount > 0 ? [
                'district_id'    => "{$groupKey}:{$overPn}",
                'scope_id'       => "{$groupKey}:{$overPn}",
                'scope_name'     => 'Panel ' . $overPn,
                'district_label' => 'Panel ' . $overPn,
                'deviation_pct'  => $overDev,
            ] : null,
            'most_under' => $panelCount > 0 ? [
                'district_id'    => "{$groupKey}:{$underPn}",
                'scope_id'       => "{$groupKey}:{$underPn}",
                'scope_name'     => 'Panel ' . $underPn,
                'district_label' => 'Panel ' . $underPn,
                'deviation_pct'  => $underDev,
            ] : null,
            'tiers'            => $eq['tiers'],
            'total_population' => $popSum,
        ];

        // contiguity: preview already carries every key the sidebar reads; add
        // `total` for parity with the Type A shape.
        $contiguity = $s['contiguity'];
        $contiguity['total'] = $panelCount;

        // shape_compactness: preview now carries per-tier population + pct.
        $sc = $s['shape_compactness'];
        $shapeCompactness = [
            'mean'             => $sc['mean'],
            'scored'           => $sc['checked_count'],
            'total'            => $panelCount,
            'total_population' => $sc['total_population'] ?? 0,
            'tiers'            => $sc['tiers'],
        ];

        return [
            'population_equality' => $populationEquality,
            'shape_compactness'   => $shapeCompactness,
            'contiguity'          => $contiguity,
            'community_integrity' => [
                'pct'              => 100.0,
                'good_count'       => $panelCount,
                'total_count'      => $panelCount,
                'good_population'  => $popSum,
                'total_population' => $popSum,
            ],
        ];
    }

    // ── Map (grouping) management API ─────────────────────────────────────────

    /**
     * POST /api/legislatures/{id}/type-b-map/maps — create an EMPTY draft
     * grouping (0 panels, every constituent unassigned), like Type A createMap.
     * The operator then fills it with Autoseed or hand-builds panels. Returns
     * { id } so the component navigates to ?map={id}. (Creation and seeding are
     * separate — Autoseed is the compute step.)
     */
    public function createMap(Request $request, string $legislature_id): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);
        $legId = $this->resolveLegislatureId($legislature_id);
        abort_unless($legId !== null, 404);

        $name = trim((string) $request->input('name', ''));
        $id = $this->insertEmptyDraft($legId, $name !== '' ? $name : null);

        return response()->json(['id' => $id, 'ok' => true, 'maps' => $this->groupingList($legId)]);
    }

    /** Insert a bare draft grouping (0 panels). Returns its id. */
    private function insertEmptyDraft(string $legId, ?string $name = null): string
    {
        $leg = DB::table('legislatures')->where('id', $legId)->first(['type_a_seats', 'type_b_rep_floor']);
        $id = (string) Str::uuid();
        DB::table('legislature_type_b_groupings')->insert([
            'id'              => $id,
            'legislature_id'  => (string) $legId,
            'status'          => 'draft',
            'name'            => $name,
            'rep_floor'       => max(2, (int) ($leg->type_b_rep_floor ?? 2)),
            'group_size'      => 0,
            'panel_count'     => 0,
            'seats_total'     => 0,
            'type_a_bound'    => (int) $leg->type_a_seats,
            'tie_break_key'   => TypeBDistrictMapper::TIE_BREAK_KEY,
            'signature'       => null,
            'effective_start' => null,
            'effective_end'   => null,
            'notes'           => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return $id;
    }

    /**
     * PATCH /api/legislatures/{id}/type-b-map/maps/{mapId} — rename a grouping.
     * Persists the name (nullable — blank clears it back to the derived name).
     */
    public function updateMap(Request $request, string $legislature_id, string $map_id): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);
        $legId = $this->resolveLegislatureId($legislature_id);
        abort_unless($legId !== null, 404);

        $g = DB::table('legislature_type_b_groupings')
            ->where('id', $map_id)->where('legislature_id', $legId)->whereNull('deleted_at')->first(['id']);
        if (! $g) {
            return response()->json(['error' => 'Grouping not found.'], 404);
        }

        $name = trim((string) $request->input('name', ''));
        DB::table('legislature_type_b_groupings')->where('id', $map_id)->update([
            'name'       => $name !== '' ? $name : null,
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'renamed' => true, 'maps' => $this->groupingList($legId)]);
    }

    /**
     * POST /api/legislatures/{id}/type-b-map/maps/{mapId}/copy — duplicate a
     * grouping (panels + members) under a new id, status draft.
     */
    public function copyMap(Request $request, string $legislature_id, string $map_id): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);
        $legId = $this->resolveLegislatureId($legislature_id);
        abort_unless($legId !== null, 404);

        $src = DB::table('legislature_type_b_groupings')
            ->where('id', $map_id)->where('legislature_id', $legId)->whereNull('deleted_at')->first();
        if (! $src) {
            return response()->json(['error' => 'Grouping not found.'], 404);
        }

        $copyName = 'Copy of ' . $this->mapName($src);
        $newId = DB::transaction(function () use ($src, $legId, $copyName) {
            $newGroupingId = (string) Str::uuid();
            DB::table('legislature_type_b_groupings')->insert([
                'id'              => $newGroupingId,
                'legislature_id'  => (string) $legId,
                'status'          => 'draft',
                'name'            => $copyName,
                'rep_floor'       => $src->rep_floor,
                'group_size'      => $src->group_size,
                'panel_count'     => $src->panel_count,
                'seats_total'     => $src->seats_total,
                'type_a_bound'    => $src->type_a_bound,
                'tie_break_key'   => $src->tie_break_key,
                'signature'       => $src->signature,
                'effective_start' => null,
                'effective_end'   => null,
                'notes'           => $src->notes,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $panels = DB::table('legislature_type_b_panels')
                ->where('grouping_id', $src->id)->whereNull('deleted_at')->get();
            foreach ($panels as $panel) {
                $newPanelId = (string) Str::uuid();
                DB::table('legislature_type_b_panels')->insert([
                    'id'             => $newPanelId,
                    'grouping_id'    => $newGroupingId,
                    'legislature_id' => (string) $legId,
                    'panel_number'   => $panel->panel_number,
                    'seats'          => $panel->seats,
                    'bonus_seats'    => $panel->bonus_seats ?? 0,
                    'member_count'   => $panel->member_count,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
                $members = DB::table('legislature_type_b_panel_jurisdictions')
                    ->where('panel_id', $panel->id)->get(['jurisdiction_id']);
                $rows = [];
                foreach ($members as $m) {
                    $rows[] = [
                        'id'              => (string) Str::uuid(),
                        'panel_id'        => $newPanelId,
                        'grouping_id'     => $newGroupingId,
                        'jurisdiction_id' => $m->jurisdiction_id,
                    ];
                }
                if ($rows !== []) {
                    DB::table('legislature_type_b_panel_jurisdictions')->insert($rows);
                }
            }

            return $newGroupingId;
        });

        return response()->json(['id' => $newId, 'ok' => true, 'maps' => $this->groupingList($legId)]);
    }

    /**
     * DELETE /api/legislatures/{id}/type-b-map/maps/{mapId} — hard-delete a
     * NON-active grouping and its panels/members. The active (seated) grouping
     * is protected.
     */
    public function deleteMap(Request $request, string $legislature_id, string $map_id): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);
        $legId = $this->resolveLegislatureId($legislature_id);
        abort_unless($legId !== null, 404);

        $g = DB::table('legislature_type_b_groupings')
            ->where('id', $map_id)->where('legislature_id', $legId)->whereNull('deleted_at')->first(['id', 'status']);
        if (! $g) {
            return response()->json(['error' => 'Grouping not found.'], 404);
        }
        if ($g->status === 'active') {
            return response()->json(['error' => 'The active grouping is seated. Activate another grouping first.'], 422);
        }

        DB::table('legislature_type_b_panel_jurisdictions')->where('grouping_id', $map_id)->delete();
        DB::table('legislature_type_b_panels')->where('grouping_id', $map_id)->delete();
        DB::table('legislature_type_b_groupings')->where('id', $map_id)->delete();

        return response()->json(['ok' => true, 'maps' => $this->groupingList($legId)]);
    }

    /**
     * POST /api/legislatures/{id}/type-b-map/maps/{mapId}/activate — promote a
     * grouping to active (archiving the prior active) and reseat the chamber.
     * Preserves the specific grouping id (and any hand-edits to a draft) rather
     * than recomputing.
     */
    public function activateMap(Request $request, string $legislature_id, string $map_id): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);
        $legId = $this->resolveLegislatureId($legislature_id);
        abort_unless($legId !== null, 404);

        // A blank/unsaved id ('preview' or anything non-UUID) must NOT silently
        // recompute a fresh plan — that would seat something other than what the
        // operator selected. Refuse before the UUID column query (which would
        // otherwise 22P02 on 'preview'). Autoseed is the compute step.
        if (! Str::isUuid($map_id)) {
            return response()->json(['error' => 'Save this grouping first (New map or Autoseed), then activate it.'], 422);
        }

        $g = DB::table('legislature_type_b_groupings')
            ->where('id', $map_id)->where('legislature_id', $legId)->whereNull('deleted_at')->first();

        if (! $g) {
            return response()->json(['error' => 'Save this grouping first (New map or Autoseed), then activate it.'], 422);
        }
        if ($g->status === 'active') {
            return response()->json(['ok' => true, 'maps' => $this->groupingList($legId)]);
        }
        if ((int) $g->panel_count < 1) {
            return response()->json(['error' => 'This map has no panels. Autoseed or build panels before activating.'], 422);
        }

        $this->promoteToActive($legId, $g);

        return response()->json(['ok' => true, 'grouping_id' => (string) $g->id, 'maps' => $this->groupingList($legId)]);
    }

    // ── Panel (district) editing API — DRAFT groupings only ───────────────────

    /**
     * POST /api/legislatures/{id}/type-b-map/panels — create a new panel from a
     * set of constituents on a DRAFT grouping (map_id). Members are stolen from
     * their current panels. Active groupings and the synthetic preview are
     * refused (nothing to persist / seated).
     */
    public function createPanel(Request $request, string $legislature_id): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);
        $legId = $this->resolveLegislatureId($legislature_id);
        abort_unless($legId !== null, 404);

        $jids  = array_values(array_filter((array) $request->input('jurisdiction_ids', [])));
        $mapId = (string) $request->input('map_id', '');
        if ($jids === []) {
            return response()->json(['error' => 'No constituents selected.'], 422);
        }

        $grouping = $this->editableDraft($legId, $mapId);
        if ($grouping instanceof JsonResponse) {
            return $grouping;
        }

        $populations = $this->constituentPopulations($legId);
        $district = DB::transaction(function () use ($grouping, $jids, $legId, $populations) {
            $nextNum = (int) DB::table('legislature_type_b_panels')
                ->where('grouping_id', $grouping->id)->whereNull('deleted_at')->max('panel_number') + 1;
            $inhabited = count(array_filter($jids, fn ($j) => (int) ($populations[$j] ?? 0) > 0));
            $panelId = (string) Str::uuid();
            DB::table('legislature_type_b_panels')->insert([
                'id'             => $panelId,
                'grouping_id'    => $grouping->id,
                'legislature_id' => (string) $legId,
                'panel_number'   => $nextNum,
                'seats'          => $grouping->rep_floor,
                'member_count'   => $inhabited,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
            // Steal each constituent from its current panel in this grouping.
            DB::table('legislature_type_b_panel_jurisdictions')
                ->where('grouping_id', $grouping->id)->whereIn('jurisdiction_id', $jids)->delete();
            $rows = array_map(fn ($j) => [
                'id'              => (string) Str::uuid(),
                'panel_id'        => $panelId,
                'grouping_id'     => $grouping->id,
                'jurisdiction_id' => $j,
            ], $jids);
            DB::table('legislature_type_b_panel_jurisdictions')->insert($rows);

            $this->resyncGrouping($grouping->id, $legId, $populations);
            // Stealing members can leave a source panel empty — absorb it.
            $this->pruneEmptyPanels($grouping->id, $legId, $populations);

            return ['id' => "{$grouping->id}:{$nextNum}", 'number' => $nextNum, 'seats' => (int) $grouping->rep_floor];
        });

        return response()->json([
            'district' => [
                'id'                => $district['id'],
                'seats'             => $district['seats'],
                'floor_override'    => false,
                'fractional_seats'  => (float) $district['seats'],
                'color_index'       => ($district['number'] - 1) % 7,
                'status'            => 'draft',
                'district_number'   => $district['number'],
                'name'              => 'Panel ' . $district['number'],
                'convex_hull_ratio' => null,
                'is_contiguous'     => null,
                'has_integrity'     => true,
            ],
            'affected_districts' => [],
            'color_indices'      => [],
        ]);
    }

    /**
     * PATCH /api/legislatures/{id}/type-b-map/panels/{panelId}/members — move
     * constituents between panels on a DRAFT grouping. panelId is the synthetic
     * "{groupingId}:{panelNumber}".
     */
    public function updatePanelMembers(Request $request, string $legislature_id, string $district_id): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);
        $legId = $this->resolveLegislatureId($legislature_id);
        abort_unless($legId !== null, 404);

        [$groupingId, $panelNumber] = $this->parseDistrictId($district_id);
        $grouping = $this->editableDraft($legId, $groupingId);
        if ($grouping instanceof JsonResponse) {
            return $grouping;
        }

        $panel = DB::table('legislature_type_b_panels')
            ->where('grouping_id', $grouping->id)->where('panel_number', $panelNumber)->whereNull('deleted_at')->first();
        if (! $panel) {
            return response()->json(['error' => 'Panel not found.'], 404);
        }

        $add    = array_values(array_filter((array) $request->input('add', [])));
        $remove = array_values(array_filter((array) $request->input('remove', [])));
        $populations = $this->constituentPopulations($legId);

        DB::transaction(function () use ($grouping, $panel, $add, $remove, $legId, $populations) {
            if ($remove !== []) {
                DB::table('legislature_type_b_panel_jurisdictions')
                    ->where('panel_id', $panel->id)->whereIn('jurisdiction_id', $remove)->delete();
            }
            if ($add !== []) {
                // Steal from any source panel in this grouping, then attach here.
                DB::table('legislature_type_b_panel_jurisdictions')
                    ->where('grouping_id', $grouping->id)->whereIn('jurisdiction_id', $add)->delete();
                $rows = array_map(fn ($j) => [
                    'id'              => (string) Str::uuid(),
                    'panel_id'        => $panel->id,
                    'grouping_id'     => $grouping->id,
                    'jurisdiction_id' => $j,
                ], $add);
                DB::table('legislature_type_b_panel_jurisdictions')->insert($rows);
            }
            $this->resyncGrouping($grouping->id, $legId, $populations);
            // A panel whose members were all moved out is ABSORBED — remove it
            // (a 0-member panel elects nobody; operator 2026-09-05).
            $this->pruneEmptyPanels($grouping->id, $legId, $populations);
        });

        return response()->json([
            'district' => [
                'id'                => $district_id,
                'seats'             => (int) $grouping->rep_floor,
                'floor_override'    => false,
                'fractional_seats'  => (float) $grouping->rep_floor,
                'color_index'       => ($panelNumber - 1) % 7,
                'status'            => 'draft',
                'district_number'   => $panelNumber,
                'name'              => 'Panel ' . $panelNumber,
                'convex_hull_ratio' => null,
                'is_contiguous'     => null,
                'has_integrity'     => true,
            ],
            'affected_districts' => [],
            'color_indices'      => [],
        ]);
    }

    /**
     * DELETE /api/legislatures/{id}/type-b-map/panels/{panelId} — disband a
     * panel on a DRAFT grouping (its constituents become unassigned). Remaining
     * panels are renumbered 1..k so synthetic ids stay contiguous.
     */
    public function deletePanel(Request $request, string $legislature_id, string $district_id): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);
        $legId = $this->resolveLegislatureId($legislature_id);
        abort_unless($legId !== null, 404);

        [$groupingId, $panelNumber] = $this->parseDistrictId($district_id);
        $grouping = $this->editableDraft($legId, $groupingId);
        if ($grouping instanceof JsonResponse) {
            return $grouping;
        }

        $panel = DB::table('legislature_type_b_panels')
            ->where('grouping_id', $grouping->id)->where('panel_number', $panelNumber)->whereNull('deleted_at')->first();
        if (! $panel) {
            return response()->json(['error' => 'Panel not found.'], 404);
        }

        $populations = $this->constituentPopulations($legId);
        DB::transaction(function () use ($grouping, $panel, $legId, $populations) {
            DB::table('legislature_type_b_panel_jurisdictions')->where('panel_id', $panel->id)->delete();
            DB::table('legislature_type_b_panels')->where('id', $panel->id)->delete();
            $this->renumberPanels($grouping->id);
            $this->resyncGrouping($grouping->id, $legId, $populations);
        });

        return response()->json(['ok' => true, 'district_numbers' => [], 'color_indices' => []]);
    }

    // ── Autoseed / status / halt / clear ──────────────────────────────────────

    /**
     * POST /api/legislatures/{id}/type-b-map/autoseed — FILL the current draft
     * map with the computed clumping (like the Type A autoseed reseeds the
     * current map). If a draft (map_id) is selected it is filled in place, keeping
     * its id and name; otherwise a fresh empty draft is created and filled.
     * Deterministic + synchronous — not a queued job, so mass-status is always
     * not-running.
     */
    public function autoseed(Request $request, string $legislature_id, TypeBDistrictMapper $mapper): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);
        $legId = $this->resolveLegislatureId($legislature_id);
        abort_unless($legId !== null, 404);

        $mapId = (string) $request->input('map_id', '');
        // operation_scope from the two-option modal: '*_unassigned' fills only the
        // gaps into the existing panels; anything else ('*_all') redoes the whole
        // map. Type B is flat, so the Type A "recursively" scopes never arrive.
        $op = (string) $request->input('operation_scope', 'map_view_all');
        $fillGapsOnly = str_contains($op, 'unassigned');

        $targetId = null;
        if (Str::isUuid($mapId)) {
            $draft = DB::table('legislature_type_b_groupings')
                ->where('id', $mapId)->where('legislature_id', $legId)->where('status', 'draft')->whereNull('deleted_at')
                ->first(['id']);
            if ($draft) {
                $targetId = (string) $draft->id;
            }
        }
        if ($targetId === null) {
            $targetId = $this->insertEmptyDraft($legId);
        }

        $hasPanels = DB::table('legislature_type_b_panels')
            ->where('grouping_id', $targetId)->whereNull('deleted_at')->exists();

        // Fill-gaps only when the map already has panels to fill into; otherwise a
        // full compute (an empty map has nothing to keep).
        $result = ($fillGapsOnly && $hasPanels)
            ? $this->fillUnassigned($legId, $targetId)
            : $mapper->applyInto($legId, $targetId);

        return response()->json([
            'ok'                => $result !== null,
            'running'           => false,
            'grouping'          => $result,
            'grouping_id'       => $targetId,
            'districts_created' => $result['panel_count'] ?? 0,
            'maps'              => $this->groupingList($legId),
        ]);
    }

    /**
     * Fill the UNASSIGNED inhabited constituents of a draft into its existing
     * panels, keeping those panels — each is attached to the panel it shares the
     * most border with (ties by lowest panel number), else the lowest-numbered
     * panel. The Type B analogue of Type A "Unassigned — fill gaps". (This is an
     * interim placement; it follows whatever the clumping formula becomes.)
     *
     * @return array{grouping_id:string, panel_count:int, seats:int, undercount:bool}
     */
    private function fillUnassigned(string $legId, string $groupingId): array
    {
        $parentId = DB::table('legislatures')->where('id', $legId)->value('jurisdiction_id');

        $panels = DB::table('legislature_type_b_panels')
            ->where('grouping_id', $groupingId)->whereNull('deleted_at')->get(['id', 'panel_number']);
        $panelIdByNum = [];
        foreach ($panels as $pn) {
            $panelIdByNum[(int) $pn->panel_number] = $pn->id;
        }
        $lowestPanel = (int) $panels->min('panel_number');

        // Existing member → its panel number (also grows as we place gaps).
        $memberPanelNum = [];
        foreach (DB::table('legislature_type_b_panel_jurisdictions as m')
            ->join('legislature_type_b_panels as p', 'p.id', '=', 'm.panel_id')
            ->where('m.grouping_id', $groupingId)->whereNull('p.deleted_at')
            ->get(['m.jurisdiction_id', 'p.panel_number']) as $r) {
            $memberPanelNum[(string) $r->jurisdiction_id] = (int) $r->panel_number;
        }

        $adjacency = [];
        foreach (DB::table('jurisdiction_adjacency')
            ->where('parent_id', $parentId)->where('dim', '>=', 1)->get(['j1', 'j2', 'border_len']) as $e) {
            $len = (float) $e->border_len;
            $adjacency[(string) $e->j1][(string) $e->j2] = $len;
            $adjacency[(string) $e->j2][(string) $e->j1] = $len;
        }

        $populations = $this->constituentPopulations($legId);
        $inserts = [];
        foreach ($populations as $jid => $pop) {
            $jid = (string) $jid;
            if (isset($memberPanelNum[$jid])) {
                continue; // already-assigned parts are skipped (zero-pop parts are members too, B3)
            }
            // Most-border panel (ties by lowest panel number).
            $borderToPanel = [];
            foreach ($adjacency[$jid] ?? [] as $nbr => $len) {
                $nbr = (string) $nbr;
                if (isset($memberPanelNum[$nbr])) {
                    $borderToPanel[$memberPanelNum[$nbr]] = ($borderToPanel[$memberPanelNum[$nbr]] ?? 0.0) + (float) $len;
                }
            }
            $targetNum = $lowestPanel;
            if ($borderToPanel !== []) {
                $bestB = -1.0;
                foreach ($borderToPanel as $pnum => $b) {
                    if ($b > $bestB || ($b === $bestB && $pnum < $targetNum)) {
                        $bestB = $b; $targetNum = (int) $pnum;
                    }
                }
            }
            $inserts[] = [
                'id'              => (string) Str::uuid(),
                'panel_id'        => $panelIdByNum[$targetNum],
                'grouping_id'     => $groupingId,
                'jurisdiction_id' => $jid,
            ];
            $memberPanelNum[$jid] = $targetNum; // later gaps may attach to this one
        }
        if ($inserts !== []) {
            DB::table('legislature_type_b_panel_jurisdictions')->insert($inserts);
        }

        $this->resyncGrouping($groupingId, $legId, $populations);
        $g = DB::table('legislature_type_b_groupings')->where('id', $groupingId)->first(['panel_count', 'seats_total']);

        return ['grouping_id' => $groupingId, 'panel_count' => (int) $g->panel_count, 'seats' => (int) $g->seats_total, 'undercount' => false];
    }

    /**
     * GET /api/legislatures/{id}/type-b-map/status — Type B clumping is
     * synchronous, so there is never a background job. Always not-running; the
     * poller stops cleanly on the first tick.
     */
    public function status(Request $request, string $legislature_id): JsonResponse
    {
        return response()->json(['running' => false, 'mass_progress' => null]);
    }

    /** POST /api/legislatures/{id}/type-b-map/halt — nothing runs; report cleared. */
    public function halt(Request $request, string $legislature_id): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        return response()->json(['ok' => true, 'cleared' => true]);
    }

    /**
     * POST /api/legislatures/{id}/type-b-map/clear — EMPTY the current draft
     * map's panels (constituents return to unassigned), leaving a blank editable
     * map — the Type B analogue of Type A mass-disband. The grouping row is kept
     * so the operator can immediately hand-build or re-autoseed. An active
     * (seated) map is refused; a blank/absent map is a no-op.
     */
    public function clear(Request $request, string $legislature_id): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);
        $legId = $this->resolveLegislatureId($legislature_id);
        abort_unless($legId !== null, 404);

        $mapId = (string) $request->input('map_id', '');
        $blank = fn (int $n) => response()->json([
            'ok' => true, 'running' => false, 'districts_deleted' => $n,
            'scopes_processed' => 1, 'maps' => $this->groupingList($legId),
        ]);

        if ($mapId === '' || $mapId === 'preview' || ! Str::isUuid($mapId)) {
            return $blank(0); // nothing (real) selected — already blank
        }
        $g = DB::table('legislature_type_b_groupings')
            ->where('id', $mapId)->where('legislature_id', $legId)->whereNull('deleted_at')->first(['id', 'status']);
        if (! $g) {
            return $blank(0);
        }
        if ($g->status !== 'draft') {
            return response()->json(['error' => 'Only a draft map can be cleared. The active map is seated — deactivate it first.'], 422);
        }

        $panelCount = (int) DB::table('legislature_type_b_panels')
            ->where('grouping_id', $g->id)->whereNull('deleted_at')->count();
        $populations = $this->constituentPopulations($legId);
        DB::transaction(function () use ($g, $legId, $populations) {
            DB::table('legislature_type_b_panel_jurisdictions')->where('grouping_id', $g->id)->delete();
            DB::table('legislature_type_b_panels')->where('grouping_id', $g->id)->delete();
            $this->resyncGrouping($g->id, $legId, $populations);
        });

        return $blank($panelCount);
    }

    /**
     * POST /api/legislatures/{id}/type-b-map/deactivate — retire the active
     * (seated) grouping so the chamber returns to an unseated/blank state. The
     * active plan is archived (history kept) and the chamber is re-flagged for
     * districting. This is the only lawful way to remove a seated grouping.
     */
    public function deactivate(Request $request, string $legislature_id): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);
        $legId = $this->resolveLegislatureId($legislature_id);
        abort_unless($legId !== null, 404);

        DB::transaction(function () use ($legId) {
            DB::table('legislature_type_b_groupings')
                ->where('legislature_id', $legId)->where('status', 'active')->whereNull('deleted_at')
                ->update(['status' => 'archived', 'effective_end' => now()->toDateString(), 'updated_at' => now()]);

            $typeA = (int) DB::table('legislatures')->where('id', $legId)->value('type_a_seats');
            DB::table('legislatures')->where('id', $legId)->update([
                'type_b_seats'             => 0,
                'type_b_needs_districting' => true,
                'total_seats'              => $typeA,
                'quorum_required'          => max(3, (int) ceil($typeA / 2)),
                'updated_at'               => now(),
            ]);
        });

        return response()->json(['ok' => true, 'maps' => $this->groupingList($legId)]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Parse a synthetic district id "{groupingId}:{panelNumber}". */
    private function parseDistrictId(string $id): array
    {
        $pos = strrpos($id, ':');
        if ($pos === false) {
            return [$id, 0];
        }

        return [substr($id, 0, $pos), (int) substr($id, $pos + 1)];
    }

    /**
     * Resolve an editable DRAFT grouping, or a JsonResponse error to return
     * directly. Preview (unsaved) and active (seated) groupings are refused.
     */
    private function editableDraft(string $legId, string $groupingId)
    {
        if ($groupingId === '' || $groupingId === 'preview') {
            return response()->json(['error' => 'Save this grouping first (New map or Autoseed), then edit it.'], 422);
        }
        $g = DB::table('legislature_type_b_groupings')
            ->where('id', $groupingId)->where('legislature_id', $legId)->whereNull('deleted_at')->first();
        if (! $g) {
            return response()->json(['error' => 'Grouping not found.'], 404);
        }
        if ($g->status !== 'draft') {
            return response()->json(['error' => 'Only a draft grouping can be edited. Edit a draft, then activate it.'], 422);
        }

        return $g;
    }

    /** Constituent id => population for this legislature's parent jurisdiction. */
    private function constituentPopulations(string $legId): array
    {
        $parentId = DB::table('legislatures')->where('id', $legId)->value('jurisdiction_id');
        $out = [];
        DB::table('jurisdictions')->where('parent_id', $parentId)->whereNull('deleted_at')
            ->get(['id', 'population'])->each(function ($r) use (&$out) {
                $out[(string) $r->id] = (int) $r->population;
            });

        return $out;
    }

    /** Recompute member_count / panel_count / seats_total / group_size after an edit. */
    private function resyncGrouping(string $groupingId, string $legId, array $populations): void
    {
        $panels = DB::table('legislature_type_b_panels')
            ->where('grouping_id', $groupingId)->whereNull('deleted_at')->get(['id', 'seats']);
        $groupSize  = 0;
        $seatsTotal = 0;
        foreach ($panels as $panel) {
            // member_count is the WHOLE clump — zero-population parts included
            // (B3 2026-09-05): they are members that vote with the clump.
            $count = (int) DB::table('legislature_type_b_panel_jurisdictions')
                ->where('panel_id', $panel->id)->count();
            $groupSize   = max($groupSize, $count);
            $seatsTotal += (int) $panel->seats;
            DB::table('legislature_type_b_panels')->where('id', $panel->id)
                ->update(['member_count' => $count, 'updated_at' => now()]);
        }
        // seats_total is the SUM of the panels' own seat counts (a bonus panel
        // carries rep_floor + 1), never panel_count × rep_floor.
        DB::table('legislature_type_b_groupings')->where('id', $groupingId)->update([
            'panel_count' => $panels->count(),
            'seats_total' => $seatsTotal,
            'group_size'  => $groupSize,
            'updated_at'  => now(),
        ]);
    }

    /**
     * Delete panels left with zero members (absorbed by an edit) and renumber +
     * resync the grouping. member_count must be current (call resyncGrouping
     * first). A 0-member panel elects nobody, so it must not persist.
     */
    private function pruneEmptyPanels(string $groupingId, string $legId, array $populations): void
    {
        $empty = DB::table('legislature_type_b_panels')
            ->where('grouping_id', $groupingId)->whereNull('deleted_at')
            ->where('member_count', 0)->pluck('id');
        if ($empty->isEmpty()) {
            return;
        }
        DB::table('legislature_type_b_panel_jurisdictions')->whereIn('panel_id', $empty)->delete();
        DB::table('legislature_type_b_panels')->whereIn('id', $empty)->delete();
        $this->renumberPanels($groupingId);
        $this->resyncGrouping($groupingId, $legId, $populations);
    }

    /** Renumber a grouping's live panels to a contiguous 1..k (order preserved). */
    private function renumberPanels(string $groupingId): void
    {
        $panels = DB::table('legislature_type_b_panels')
            ->where('grouping_id', $groupingId)->whereNull('deleted_at')->orderBy('panel_number')->get(['id']);
        // Offset out of the unique (grouping_id, panel_number) band first.
        $offset = 1000;
        foreach ($panels as $i => $panel) {
            DB::table('legislature_type_b_panels')->where('id', $panel->id)
                ->update(['panel_number' => $offset + $i]);
        }
        foreach ($panels as $i => $panel) {
            DB::table('legislature_type_b_panels')->where('id', $panel->id)
                ->update(['panel_number' => $i + 1, 'updated_at' => now()]);
        }
    }

    /**
     * Promote a specific grouping to active: archive the prior active, flip this
     * one to active, and reseat the chamber (type_b_seats / total_seats / quorum
     * / clear type_b_needs_districting) — the same chamber math apply() performs.
     */
    private function promoteToActive(string $legId, object $grouping): void
    {
        DB::transaction(function () use ($legId, $grouping) {
            DB::table('legislature_type_b_groupings')
                ->where('legislature_id', $legId)->where('status', 'active')->whereNull('deleted_at')
                ->update(['status' => 'archived', 'effective_end' => now()->toDateString(), 'updated_at' => now()]);

            DB::table('legislature_type_b_groupings')->where('id', $grouping->id)->update([
                'status'          => 'active',
                'effective_start' => now()->toDateString(),
                'effective_end'   => null,
                'updated_at'      => now(),
            ]);

            $typeA = (int) DB::table('legislatures')->where('id', $legId)->value('type_a_seats');
            $seats = (int) $grouping->seats_total;
            $total = $typeA + $seats;
            DB::table('legislatures')->where('id', $legId)->update([
                'type_b_seats'             => $seats,
                'type_b_needs_districting' => false,
                'total_seats'              => $total,
                'quorum_required'          => max(3, (int) ceil($total / 2)),
                'updated_at'               => now(),
            ]);
        });
    }

    /**
     * Collision-free colours for the panels: greedy adjacency 7-colouring, the
     * same algorithm Type A uses (LegislatureController::colorIndicesForDistricts).
     * Two panels are adjacent when a member of one shares a border with a member
     * of the other (the constituent adjacency graph). Panels are coloured
     * highest-degree first; each takes the lowest colour (0-6) no coloured
     * neighbour holds. Returns panel_number => color_index.
     *
     * @param list<array<string,mixed>> $panels  preview panels (each with panel_number + members[])
     * @return array<int,int>
     */
    private function panelColorIndices(array $panels, string $parentId): array
    {
        if ($panels === []) {
            return [];
        }
        // member jurisdiction id => panel_number
        $panelOf = [];
        foreach ($panels as $p) {
            $pn = (int) $p['panel_number'];
            foreach ($p['members'] as $m) {
                $panelOf[(string) $m] = $pn; // preview panels carry member id STRINGS
            }
        }
        // Panel adjacency from the constituent adjacency graph (this parent only).
        $adj = [];
        foreach ($panels as $p) {
            $adj[(int) $p['panel_number']] = [];
        }
        $seen = [];
        foreach (DB::table('jurisdiction_adjacency')
            ->where('parent_id', $parentId)->where('dim', '>=', 1)
            ->get(['j1', 'j2']) as $e) {
            $pa = $panelOf[(string) $e->j1] ?? null;
            $pb = $panelOf[(string) $e->j2] ?? null;
            if ($pa === null || $pb === null || $pa === $pb) {
                continue;
            }
            $key = $pa < $pb ? "{$pa}-{$pb}" : "{$pb}-{$pa}";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key]  = true;
            $adj[$pa][]  = $pb;
            $adj[$pb][]  = $pa;
        }
        // Highest-degree first (greedy does best when the hard nodes go early),
        // ties by panel_number for determinism.
        $order = array_keys($adj);
        usort($order, function (int $a, int $b) use ($adj): int {
            $d = count($adj[$b]) - count($adj[$a]);
            return $d !== 0 ? $d : ($a <=> $b);
        });
        $colors = [];
        foreach ($order as $pn) {
            $taken = [];
            foreach ($adj[$pn] as $nb) {
                if (isset($colors[$nb])) {
                    $taken[$colors[$nb]] = true;
                }
            }
            for ($c = 0; $c < 7; $c++) {
                if (! isset($taken[$c])) {
                    $colors[$pn] = $c;
                    break;
                }
            }
            if (! isset($colors[$pn])) {
                $colors[$pn] = 0; // unreachable for a planar map (no K8)
            }
        }

        return $colors;
    }

    /** Display name: the stored name if set, else derived from status/date. */
    private function mapName(object $g): string
    {
        if (! empty($g->name)) {
            return (string) $g->name;
        }
        $when = ! empty($g->created_at) ? \Illuminate\Support\Carbon::parse($g->created_at)->format('Y-m-d') : '';

        return ucfirst((string) $g->status) . ' grouping' . ($when !== '' ? " · {$when}" : '');
    }

    /** The chamber's groupings as rows (active, then drafts, then archived). */
    private function groupingRows(string $legId)
    {
        return DB::table('legislature_type_b_groupings')
            ->where('legislature_id', $legId)
            ->whereNull('deleted_at')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->get(['id', 'status', 'name', 'panel_count', 'seats_total', 'group_size', 'effective_start', 'effective_end', 'created_at']);
    }

    /** The chamber's groupings for the MAP selector (id/name/status/count). */
    private function groupingList(string $legId): array
    {
        return $this->groupingRows($legId)->map(fn ($g) => [
            'id'              => (string) $g->id,
            'name'            => $this->mapName($g),
            'status'          => $g->status,
            'district_count'  => (int) $g->panel_count,
            'seats'           => (int) $g->seats_total,
            'group_size'      => (int) $g->group_size,
            'effective_start' => $g->effective_start,
            'effective_end'   => $g->effective_end,
            'flags'           => null,
            'total_flags'     => null,
        ])->all();
    }

    private function resolveLegislatureId(string $param): ?string
    {
        if (Str::isUuid($param)) {
            $direct = DB::table('legislatures')->where('id', $param)->whereNull('deleted_at')->value('id');
            if ($direct) {
                return (string) $direct;
            }
            $byJurisdiction = DB::table('legislatures')->where('jurisdiction_id', $param)->whereNull('deleted_at')->value('id');

            return $byJurisdiction ? (string) $byJurisdiction : null;
        }

        $jid = DB::table('jurisdictions')->where('slug', $param)->whereNull('deleted_at')->value('id');
        if (! $jid) {
            return null;
        }
        $legId = DB::table('legislatures')->where('jurisdiction_id', $jid)->whereNull('deleted_at')->value('id');

        return $legId ? (string) $legId : null;
    }
}
