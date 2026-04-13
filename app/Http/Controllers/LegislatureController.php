<?php

namespace App\Http\Controllers;

use App\Jobs\RecolorDistrictsJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LegislatureController extends Controller
{
    /**
     * Legislature browser.
     *
     * GET /legislatures/{legislature_id}[?scope={jurisdiction_id}]
     *
     * Scope defaults to the legislature's own parent jurisdiction.
     * Drill-down is achieved by passing ?scope=<child_jurisdiction_id>.
     */
    public function show(Request $request, string $legislature_id): Response|RedirectResponse
    {
        $leg = DB::table('legislatures')
            ->where('id', $legislature_id)
            ->whereNull('deleted_at')
            ->first();

        abort_if(!$leg, 404, 'Legislature not found.');

        // Scope: which level of the hierarchy to display.  Defaults to the
        // legislature's own parent jurisdiction (Earth, USA, etc.).
        $scopeId = $request->query('scope', $leg->jurisdiction_id);

        // Resolve the district map to display (URL ?map= param → active → newest draft).
        $mapId = $this->getMapId($legislature_id, $request->query('map'));

        $scope = DB::table('jurisdictions')
            ->where('id', $scopeId)
            ->whereNull('deleted_at')
            ->first();

        abort_if(!$scope, 404, 'Scope jurisdiction not found.');

        // Root jurisdiction population — always the legislature's own jurisdiction (e.g. Earth).
        // Used to compute proportional entitlements consistently across all drill-down levels.
        $rootPop = (int) DB::table('jurisdictions')
            ->where('id', $leg->jurisdiction_id)
            ->value('population');
        $rootPop = max($rootPop, 1);

        // Guard: non-root scopes must be giants (fractional_seats >= 9.5 at root quota).
        // Prevents URL-based access to non-giant sub-scopes (indivisible jurisdictions).
        if ($scopeId !== $leg->jurisdiction_id) {
            $scopeFrac = (int) $scope->population * (int) $leg->type_a_seats / $rootPop;
            if ($scopeFrac < 9.5) {
                // Walk up to find the nearest giant (or root) ancestor to redirect to.
                $redirectScopeId = $leg->jurisdiction_id;  // fallback: root
                $parentId = $scope->parent_id;
                while ($parentId && $parentId !== $leg->jurisdiction_id) {
                    $parentRow = DB::table('jurisdictions')
                        ->where('id', $parentId)
                        ->whereNull('deleted_at')
                        ->first();
                    if (!$parentRow) break;
                    $parentFrac = (int) $parentRow->population * (int) $leg->type_a_seats / $rootPop;
                    if ($parentFrac >= 9.5) {
                        $redirectScopeId = $parentId;
                        break;
                    }
                    $parentId = $parentRow->parent_id;
                }
                if ($redirectScopeId === $leg->jurisdiction_id) {
                    return redirect()->to('/legislatures/' . $legislature_id);
                }
                return redirect()->to('/legislatures/' . $legislature_id . '?scope=' . $redirectScopeId);
            }
        }

        // Scope's rounded seat entitlement from the root legislature.
        // At root scope: just the legislature's own type_a_seats (e.g. 1999).
        // At non-root scope: use SUM(direct_children.population) as denominator — the same
        // population base that runAutoCompositeForScope() uses for $scopeBudget.  This
        // eliminates the "28 vs 29" class of mismatches that arise when stored ADM0 population
        // ≠ sum of ADM1 child populations (geoBoundaries ADM-level data quality variation).
        if ($scopeId === $leg->jurisdiction_id) {
            $scopeSeats   = (int) $leg->type_a_seats;
            $effectivePop = (int) $scope->population;
        } else {
            $effectivePop = (int) DB::table('jurisdictions')
                ->where('parent_id', $scopeId)
                ->whereNull('deleted_at')
                ->sum('population');
            if ($effectivePop <= 0) {
                $effectivePop = (int) $scope->population;   // fallback for empty scopes
            }
            // Priority for scopeSeats at non-root scopes:
            // 1. type_a_apportioned — authoritative Webster result from ETL Phase 1.
            //    This is the correct budget regardless of how many districts exist in the
            //    current map (important for multi-map support: a draft map with partial
            //    districts must still show the full budget, not distSum).
            // 2. Proportional approximation — last resort before any seeding has occurred.
            if ($scope->type_a_apportioned !== null) {
                $scopeSeats = (int) $scope->type_a_apportioned;
            } else {
                $scopeSeats = max(5, (int) round($effectivePop * (int) $leg->type_a_seats / $rootPop));
            }
        }

        // Display quota: effectivePop / scope_entitlement.
        // Uses the same population base as $scopeSeats so the displayed quota matches
        // what the ETL used when seeding districts (e.g. Philippines: SUM(ADM1 pops)/29).
        $quota = max($effectivePop, 1) / max($scopeSeats, 1);

        // Children of scope with their fractional seats and current district assignment
        $mapFilter  = $mapId !== null ? 'AND ld.map_id = :map_id' : '';
        $mapBindings = $mapId !== null ? ['map_id' => $mapId] : [];

        // child_assigned_seats: seats committed inside each child's subtree at any depth.
        // A correlated one-level subquery misses grandchildren (e.g. Earth→India→ADM3).
        // A WITH RECURSIVE CTE walks all descendants (depth-limited to 5 levels) so that
        // Earth scope correctly sums UP/Maharashtra's sub-districts that contain ADM3 members.
        // PDO does not allow reusing named parameters — scope_id_r / leg_id2 / map_id2 are
        // distinct aliases for the same values used in the outer query.
        $childMapFilter2 = $mapId !== null ? 'AND ld2.map_id = :map_id2' : '';
        $childMapBindings = $mapId !== null ? ['map_id2' => $mapId] : [];

        $children = DB::select("
            WITH RECURSIVE giant_children AS (
                -- Only giant children (frac >= 9.5) ever have sub-districts; non-giants are always
                -- composited at this scope level so their child_assigned_seats is always 0.
                -- Scoping the seed to giants only reduces CTE rows from millions (all descendants
                -- of all 192 countries) to just the subtrees of ~3-5 giant countries.
                SELECT id
                FROM   jurisdictions
                WHERE  parent_id  = :scope_id_r
                  AND  deleted_at IS NULL
                  AND  (CAST(population AS numeric) * :total_seats_c / :root_pop_c) >= 9.5
            ),
            desc_tree AS (
                -- Seed: giant children only (root_child_id tracks which giant each
                -- descendant belongs to so we can GROUP BY it in child_committed)
                SELECT id, id AS root_child_id, 0 AS lvl
                FROM   giant_children
                UNION ALL
                -- Recurse: one level deeper, capped at lvl 4 (covers ADM0→ADM3 hierarchies)
                SELECT j.id, d.root_child_id, d.lvl + 1
                FROM   jurisdictions j
                JOIN   desc_tree d ON j.parent_id = d.id
                WHERE  j.deleted_at IS NULL
                  AND  d.lvl < 4
            ),
            child_committed AS (
                -- For each scope child, sum the seats of DISTINCT districts whose members
                -- appear anywhere in that child's subtree.  DISTINCT on (district id,
                -- root_child_id) prevents counting a district's seats once per member.
                SELECT distinct_d.root_child_id,
                       SUM(distinct_d.seats) AS child_assigned_seats
                FROM (
                    SELECT DISTINCT ld2.id, ld2.seats, dt.root_child_id
                    FROM   desc_tree dt
                    JOIN   legislature_district_jurisdictions ldj2
                               ON ldj2.jurisdiction_id = dt.id
                    JOIN   legislature_districts ld2
                               ON ld2.id = ldj2.district_id
                              AND ld2.legislature_id = :leg_id2
                              AND ld2.deleted_at IS NULL
                              {$childMapFilter2}
                ) distinct_d
                GROUP BY distinct_d.root_child_id
            )
            SELECT DISTINCT ON (j.id)
                j.id,
                j.name,
                j.adm_level,
                j.population,
                ROUND(CAST(j.population AS numeric) / :quota, 4)  AS fractional_seats,
                ld.id                                              AS district_id,
                ld.seats                                           AS district_seats,
                ld.floor_override,
                (SELECT COUNT(*) FROM jurisdictions c WHERE c.parent_id = j.id AND c.deleted_at IS NULL) AS child_count,
                COALESCE(cc.child_assigned_seats, 0)               AS child_assigned_seats
            FROM jurisdictions j
            LEFT JOIN child_committed cc ON cc.root_child_id = j.id
            LEFT JOIN legislature_district_jurisdictions ldj ON ldj.jurisdiction_id = j.id
            LEFT JOIN legislature_districts ld
                ON ld.id = ldj.district_id
               AND ld.legislature_id = :leg_id
               AND ld.deleted_at IS NULL
               {$mapFilter}
            WHERE j.parent_id  = :scope_id
              AND j.deleted_at IS NULL
            ORDER BY j.id, ld.id NULLS LAST
        ", array_merge([
            'quota'         => $quota,
            'leg_id'        => $legislature_id,
            'leg_id2'       => $legislature_id,
            'scope_id'      => $scopeId,
            'scope_id_r'    => $scopeId,
            'total_seats_c' => (int) $leg->type_a_seats,
            'root_pop_c'    => $rootPop,
        ], $mapBindings, $childMapBindings));

        // Re-sort in PHP after DISTINCT ON forces ORDER BY j.id above.
        usort($children, fn($a, $b) => $b->population - $a->population);

        // Districts with full member data — one row per district-member pair at this scope.
        // Grouped in PHP so each district gets a `members` array with IDs for map highlighting.
        $dmRows = DB::select("
            SELECT
                ld.id               AS district_id,
                ld.seats,
                ld.floor_override,
                ld.status,
                ld.color_index,
                ld.district_number  AS dnum,
                ld.actual_population AS district_pop,
                ld.fractional_seats  AS district_frac,
                ld.convex_hull_ratio,
                ld.is_contiguous,
                j.id                AS jid,
                j.name              AS jname,
                j.population        AS jpop,
                j.iso_code          AS jiso,
                j.adm_level         AS jadm,
                ROUND(CAST(j.population AS numeric) / :quota, 4) AS jfrac,
                (SELECT COUNT(*) FROM jurisdictions c WHERE c.parent_id = j.id AND c.deleted_at IS NULL)
                                    AS jchild_count
            FROM legislature_districts ld
            JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
            JOIN jurisdictions j
                ON j.id = ldj.jurisdiction_id
               AND j.parent_id = :scope_id
               AND j.deleted_at IS NULL
            WHERE ld.legislature_id = :leg_id
              AND ld.deleted_at IS NULL
              {$mapFilter}
            ORDER BY ld.seats DESC, j.population DESC
        ", array_merge([
            'quota'    => $quota,
            'leg_id'   => $legislature_id,
            'scope_id' => $scopeId,
        ], $mapBindings));

        // Group into district objects
        $districtMap = [];
        foreach ($dmRows as $row) {
            $did = $row->district_id;
            if (!isset($districtMap[$did])) {
                $districtMap[$did] = [
                    'id'               => $did,
                    'seats'            => (int) $row->seats,
                    'floor_override'   => (bool) $row->floor_override,
                    'status'           => $row->status,
                    'color_index'      => (int) $row->color_index,
                    'district_number'  => (int) $row->dnum,
                    'population'       => (int) $row->district_pop,
                    'fractional_seats' => (float) $row->district_frac,
                    'convex_hull_ratio' => $row->convex_hull_ratio !== null ? round((float) $row->convex_hull_ratio, 3) : null,
                    'is_contiguous'     => $row->is_contiguous !== null ? (bool) $row->is_contiguous : null,
                    '_member_codes'    => [],
                    'members'          => [],
                ];
            }
            $districtMap[$did]['members'][] = [
                'id'               => $row->jid,
                'name'             => $row->jname,
                'population'       => (int) $row->jpop,
                'fractional_seats' => (float) $row->jfrac,
                'child_count'      => (int) $row->jchild_count,
            ];
            $districtMap[$did]['_member_codes'][] = $this->makeShortCode($row->jname, $row->jiso, (int) $row->jadm);
        }

        // Breadcrumb: ancestor chain from scope up to the legislature's root jurisdiction.
        // Moved before district naming so we can use ancestors[1] (first child of root)
        // as the label prefix — ensures grandchild scopes (e.g. California) label "USA 01"
        // rather than "CAL 01".
        $ancestors = DB::select("
            WITH RECURSIVE anc AS (
                SELECT id, name, parent_id, iso_code, adm_level
                FROM jurisdictions WHERE id = :start_id
                UNION ALL
                SELECT j.id, j.name, j.parent_id, j.iso_code, j.adm_level
                FROM jurisdictions j
                JOIN anc ON j.id = anc.parent_id
                WHERE j.deleted_at IS NULL
            )
            SELECT id, name, iso_code, adm_level FROM anc
        ", ['start_id' => $scopeId]);

        // Reverse so we get root → current scope
        $ancestors = array_reverse($ancestors);

        // Compute human-readable name from member codes (scope-aware)
        // Root scope: codes only, no number — "SAU", "AND-LIE-MCO" (every combo is unique)
        // Sub-scope:  scope code + sequential number — "USA 01", "USA 02"
        // Use ancestors[1] (first child of root) so grandchild scopes label correctly.
        $isRootScope    = ($scopeId === $leg->jurisdiction_id);
        $scopeShortCode = null;
        if (!$isRootScope) {
            // After array_reverse: $ancestors[0]=root, $ancestors[1]=first child of root, ...
            // At depth 2+ (e.g. California within USA), use composite "COUNTRY SCOPE" prefix
            // so "USA CAL 01" doesn't collide with USA-level "USA 01".
            $countryJur  = count($ancestors) >= 2 ? $ancestors[1] : $scope;
            $countryCode = $this->makeShortCode($countryJur->name, $countryJur->iso_code ?? null, (int) $countryJur->adm_level);
            if (count($ancestors) >= 3) {
                // e.g. California within USA → "USA CAL"
                $ownCode        = $this->makeShortCode($scope->name, $scope->iso_code ?? null, (int) $scope->adm_level);
                $scopeShortCode = $countryCode . ' ' . $ownCode;
            } else {
                $scopeShortCode = $countryCode;
            }
        }

        foreach ($districtMap as &$d) {
            $memberCount = count($d['_member_codes']);
            if ($memberCount === 1) {
                // Single-member district: the district IS the jurisdiction — just use its short code.
                $d['name'] = reset($d['_member_codes']);
            } elseif ($isRootScope) {
                // Use root jurisdiction short code + sequential number (e.g. "EAR 01")
                // instead of the long hyphenated member-code chain ("ABW-AIA-ATG-...").
                $rootShortCode = $this->makeShortCode($scope->name, $scope->iso_code ?? null, (int) $scope->adm_level);
                $num           = str_pad($d['district_number'], 2, '0', STR_PAD_LEFT);
                $d['name']     = $rootShortCode . ' ' . $num;
            } else {
                $num       = str_pad($d['district_number'], 2, '0', STR_PAD_LEFT);
                $d['name'] = $scopeShortCode . ' ' . $num;
            }
            unset($d['_member_codes']);
        }
        unset($d);

        // At non-root scopes, always recompute composite district fractional_seats so they
        // represent each district's proportional share of the ACTUAL Webster-allocated
        // composite seat total (SUM of ld.seats in districtMap). This is independent of
        // how fractional_seats was stored (root-quota vs local-quota) and guarantees:
        //   • composite fracs sum to exactly the composite seat total
        //   • grand total (composite sum + giant integer allocations) = scopeSeats exactly
        if (!$isRootScope && count($districtMap) > 0) {
            $compositeSeats = array_sum(array_map(fn($d) => $d['seats'], $districtMap));
            if ($compositeSeats > 0) {
                $dPops = array_map(fn($d) =>
                    $d['population'] > 0
                        ? $d['population']
                        : array_sum(array_column($d['members'], 'population')),
                    $districtMap);
                $totalDistrictPop = array_sum($dPops);
                if ($totalDistrictPop > 0) {
                    $localDisplayQuota = $totalDistrictPop / $compositeSeats;
                    $dPopArr = array_values($dPops);
                    $i       = 0;
                    foreach ($districtMap as &$d) {
                        $d['fractional_seats'] = round($dPopArr[$i++] / $localDisplayQuota, 4);
                    }
                    unset($d);
                }
            }
        }

        $districts = array_values($districtMap);

        // Lazy-backfill: if all districts at this scope still have color_index=0 (e.g. after
        // a reseed or data wipe), run the 4-color assignment once and update in-place.
        // recomputeColorIndices() wraps geometries in ST_SimplifyPreserveTopology to keep
        // ST_Intersects fast even on large-polygon scopes (Canada, India, etc.).  For > 50
        // districts it skips adjacency entirely and uses fast deterministic cycling instead.
        if (count($districts) > 1
            && max(array_column($districts, 'color_index')) === 0
        ) {
            $this->recomputeColorIndices($legislature_id, $scopeId, $leg->jurisdiction_id, $mapId);
            $freshColors = DB::table('legislature_districts')
                ->whereIn('id', array_column($districts, 'id'))
                ->pluck('color_index', 'id');
            foreach ($districts as &$d) {
                $d['color_index'] = (int) ($freshColors[$d['id']] ?? 0);
            }
            unset($d);
        }

        // Districts have no stored geometry — centroids are always null.
        // The revealed layer uses jurisdiction polygons (jurisdictions.geom) directly.
        foreach ($districts as &$d) {
            $d['centroid'] = null;
        }
        unset($d);

        $flags = $this->computeValidationFlags($legislature_id, $leg, $scopeId, $children, $districts, $mapId);
        $stats = $this->computeConstitutionalStats($legislature_id, $scopeId, $districts, $quota, $mapId);

        $massToolRunning = (bool) Cache::get("legislature.{$legislature_id}.mass_running", false);

        // All maps for this legislature — provides data for the map selector + comparison panel.
        $allMapsRows = DB::table('legislature_district_maps')
            ->where('legislature_id', $legislature_id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();

        // Batch all district counts in one GROUP BY query instead of N per-map COUNTs.
        $mapDistrictCounts = DB::table('legislature_districts')
            ->where('legislature_id', $legislature_id)
            ->whereNull('deleted_at')
            ->selectRaw('map_id, COUNT(*) as cnt')
            ->groupBy('map_id')
            ->pluck('cnt', 'map_id');

        // Flags for non-active maps are deferred: they are expensive (full validation pass)
        // and only needed when the comparison panel is open. Pass compare=1 to load them.
        $loadCompareFlags = $request->query('compare') === '1';

        $allMaps = $allMapsRows->map(function ($m) use ($legislature_id, $mapId, $leg, $mapDistrictCounts, $loadCompareFlags) {
            $mapFlags = null;
            if ($m->id !== $mapId && $loadCompareFlags) {
                $mapFlags = $this->computeValidationFlags(
                    $legislature_id, $leg, $leg->jurisdiction_id, [], [], $m->id
                );
            }

            return [
                'id'              => $m->id,
                'name'            => $m->name,
                'status'          => $m->status,
                'effective_start' => $m->effective_start,
                'effective_end'   => $m->effective_end,
                'district_count'  => (int) ($mapDistrictCounts[$m->id] ?? 0),
                'flags'           => $mapFlags,
                'total_flags'     => $mapFlags !== null ? $this->flagCount($mapFlags) : null,
            ];
        })->toArray();

        // Identify the currently-displayed map object for the active_map prop.
        $activeMapRow = $mapId
            ? DB::table('legislature_district_maps')->where('id', $mapId)->first()
            : null;

        return Inertia::render('Legislature/Show', [
            'legislature' => [
                'id'                   => $leg->id,
                'root_jurisdiction_id' => $leg->jurisdiction_id,
                'type_a_seats'         => (int) $leg->type_a_seats,
                'type_b_seats'         => (int) ($leg->type_b_seats ?? 0),
                'status'               => $leg->status,
            ],
            'scope' => (function () use ($scope) {
                $bboxRow = DB::selectOne("
                    SELECT ST_YMin(geom) AS south, ST_XMin(geom) AS west,
                           ST_YMax(geom) AS north, ST_XMax(geom) AS east
                    FROM jurisdictions WHERE id = ?
                ", [$scope->id]);
                return [
                    'id'         => $scope->id,
                    'name'       => $scope->name,
                    'adm_level'  => $scope->adm_level,
                    'population' => (int) $scope->population,
                    'bbox'       => $bboxRow
                        ? [(float) $bboxRow->south, (float) $bboxRow->west,
                           (float) $bboxRow->north, (float) $bboxRow->east]
                        : null,
                ];
            })(),
            'scope_seats' => $scopeSeats,   // rounded entitlement at this drill-down level
            'ancestors' => array_map(fn($a) => ['id' => $a->id, 'name' => $a->name], $ancestors),
            'children'  => array_map(fn($c) => [
                'id'               => $c->id,
                'name'             => $c->name,
                'adm_level'        => $c->adm_level,
                'population'       => (int) $c->population,
                // At non-root scopes, giant children (frac >= 9.5) display their integer
                // allocation (round of local-quota frac) so that composite_sum + giant_integers
                // = scopeSeats exactly.  Root scope keeps raw fractionals (e.g. India 357.94).
                'fractional_seats' => !$isRootScope && (float) $c->fractional_seats >= 9.5
                    ? (float) round((float) $c->fractional_seats)
                    : (float) $c->fractional_seats,
                'district_id'      => $c->district_id,
                'district_seats'   => $c->district_seats !== null ? (int) $c->district_seats : null,
                'floor_override'   => (bool) $c->floor_override,
                'child_count'          => (int) $c->child_count,
                'child_assigned_seats' => (int) ($c->child_assigned_seats ?? 0),
            ], $children),
            'districts' => $districts,  // [{id, seats, floor_override, status, color_index, district_number, name, members:[...]}]
            'quota'           => round($quota),
            'flags'           => $flags,
            'stats'           => $stats,
            'mass_tool_running' => $massToolRunning,
            'maps'       => $allMaps,
            'active_map' => $activeMapRow ? [
                'id'     => $activeMapRow->id,
                'name'   => $activeMapRow->name,
                'status' => $activeMapRow->status,
            ] : null,
        ]);
    }

    // ── District editing API ──────────────────────────────────────────────────

    /**
     * POST /api/legislatures/{legislature_id}/districts
     *
     * Create a new district from a set of jurisdiction IDs.
     * Validates that all jurisdictions share the same parent (scope).
     * Calculates Webster seat count and unions geometry via PostGIS.
     */
    public function createDistrict(Request $request, string $legislature_id): JsonResponse
    {
        $leg = DB::table('legislatures')
            ->where('id', $legislature_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$leg) {
            return response()->json(['error' => 'Legislature not found'], 404);
        }

        $jids    = $request->input('jurisdiction_ids', []);
        $scopeId = $request->input('scope_id');
        $mapId   = $this->getMapId($legislature_id, $request->input('map_id'));

        // label_scope_id: first child of root (passed from frontend for naming at grandchild scopes).
        // Falls back to actual scope if not provided (e.g., at root or depth-1).
        $labelScopeId = $request->input('label_scope_id', $scopeId);

        if (empty($jids)) {
            return response()->json(['error' => 'No jurisdictions provided'], 422);
        }

        // Validate all jurisdictions exist, are not soft-deleted, share parent = scopeId
        $jRows = DB::table('jurisdictions')
            ->whereIn('id', $jids)
            ->whereNull('deleted_at')
            ->where('parent_id', $scopeId)
            ->get(['id', 'name', 'population', 'iso_code', 'adm_level']);

        if ($jRows->count() !== count($jids)) {
            return response()->json(['error' => 'One or more jurisdictions are invalid or do not belong to the scope'], 422);
        }

        // Compute local quota: scope children population / scope seat budget.
        // Uses type_a_apportioned (set by parent autoComposite) when available; falls back to proportional.
        $rootPop = max((int) DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('population'), 1);
        $scopeRow = DB::table('jurisdictions')->where('id', $scopeId)->whereNull('deleted_at')->first();
        $scopeChildrenPop = (int) DB::table('jurisdictions')
            ->where('parent_id', $scopeId)
            ->whereNull('deleted_at')
            ->sum('population');
        if ($scopeId === $leg->jurisdiction_id) {
            $seatBudget = (int) $leg->type_a_seats;
        } elseif ($scopeRow && $scopeRow->type_a_apportioned !== null) {
            $seatBudget = (int) $scopeRow->type_a_apportioned;
        } else {
            $seatBudget = max(5, (int) round((int) ($scopeRow ? $scopeRow->population : 0) * (int) $leg->type_a_seats / $rootPop));
        }
        $localQuota = $scopeChildrenPop / max($seatBudget, 1);

        // Validate: no individual member may be a giant (frac >= 9.5) — giants cannot be composited
        foreach ($jRows as $jRow) {
            $memberFrac = (int) $jRow->population / max($localQuota, 1);
            if ($memberFrac >= 9.5) {
                return response()->json([
                    'error' => sprintf(
                        '%s has %.2f fractional seats (≥ 9.5). ' .
                        'Giant jurisdictions cannot be assigned to a district at this level — drill down instead.',
                        $jRow->name, $memberFrac
                    ),
                ], 422);
            }
        }

        // Validate: composite total fractional must be < 9.5 (rounds to ≤ 9 seats)
        $totalFrac = array_sum($jRows->map(fn($r) => (int) $r->population / max($localQuota, 1))->toArray());
        if ($totalFrac >= 9.5) {
            return response()->json([
                'error' => sprintf(
                    'Composite fractional seats (%.2f) ≥ 9.5 — would round to ≥ 10, ' .
                    'exceeding the constitutional district maximum of 9. Remove a jurisdiction.',
                    $totalFrac
                ),
            ], 422);
        }

        // Calculate fractional seats for this composite using local quota
        $totalPop   = (int) $jRows->sum('population');
        $fractional = $totalPop / max($localQuota, 1);

        // Compute effective floor — mirrors runAutoCompositeForScope() logic.
        // When giants consume most of the budget, the remaining non-giant budget
        // may be less than 5, so the floor is capped at that remainder.
        $allScopeChildPops = DB::table('jurisdictions')
            ->where('parent_id', $scopeId)
            ->whereNull('deleted_at')
            ->pluck('population');
        $giantSeatsCommitted = 0;
        foreach ($allScopeChildPops as $childPop) {
            $childFrac = (int) $childPop / max($localQuota, 1);
            if ($childFrac >= 9.5) {
                $giantSeatsCommitted += max(5, (int) round($childFrac));
            }
        }
        $nonGiantBudget = max(1, $seatBudget - $giantSeatsCommitted);
        $effectiveFloor = min(5, $nonGiantBudget);

        // Webster (Sainte-Laguë) rounding — clamp to [effectiveFloor, 9]
        $seats        = max($effectiveFloor, min(9, (int) round($fractional)));
        $floorOverride = $seats < 5;

        DB::beginTransaction();
        try {
            $districtId = (string) \Illuminate\Support\Str::uuid();

            // Assign the next district number in sequence for this legislature + scope + map
            $distNumQuery = DB::table('legislature_districts')
                ->where('legislature_id', $legislature_id)
                ->where('jurisdiction_id', $scopeId)
                ->whereNull('deleted_at');
            if ($mapId !== null) {
                $distNumQuery->where('map_id', $mapId);
            }
            $districtNumber = 1 + (int) $distNumQuery->max('district_number');

            // No geometry stored on the district record — the revealed layer renders
            // individual jurisdiction polygons (from jurisdictions.geom) directly,
            // giving full fidelity, instant response, and zero geometry computation.
            DB::table('legislature_districts')->insert([
                'id'               => $districtId,
                'legislature_id'   => $legislature_id,
                'map_id'           => $mapId,
                'jurisdiction_id'  => $scopeId,
                'district_number'  => $districtNumber,
                'seats'            => $seats,
                'fractional_seats' => $fractional,
                'floor_override'   => $floorOverride,
                'target_population'=> $totalPop,
                'actual_population'=> $totalPop,
                'status'           => 'active',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // Remove these jurisdictions from any prior district in this legislature + map
            $existDistQuery = DB::table('legislature_district_jurisdictions as ldj')
                ->join('legislature_districts as ld', 'ld.id', '=', 'ldj.district_id')
                ->where('ld.legislature_id', $legislature_id)
                ->whereIn('ldj.jurisdiction_id', $jids);
            if ($mapId !== null) {
                $existDistQuery->where('ld.map_id', $mapId);
            }
            $existingDistrictIds = $existDistQuery->pluck('ldj.district_id')
                ->unique()
                ->values()
                ->toArray();

            $deleteStealQuery = DB::table('legislature_district_jurisdictions')
                ->join('legislature_districts', 'legislature_districts.id', '=', 'legislature_district_jurisdictions.district_id')
                ->where('legislature_districts.legislature_id', $legislature_id)
                ->whereIn('legislature_district_jurisdictions.jurisdiction_id', $jids);
            if ($mapId !== null) {
                $deleteStealQuery->where('legislature_districts.map_id', $mapId);
            }
            $deleteStealQuery->delete();

            // Insert new memberships (no timestamps — table has no created_at/updated_at)
            $memberships = array_map(fn($jid) => [
                'id'              => (string) \Illuminate\Support\Str::uuid(),
                'district_id'     => $districtId,
                'jurisdiction_id' => $jid,
            ], $jids);

            DB::table('legislature_district_jurisdictions')->insert($memberships);

            // Recompute geometry + seats for districts that lost members
            foreach ($existingDistrictIds as $affectedId) {
                $this->recomputeDistrict($affectedId, $legislature_id, $leg);
            }

            // Compute and cache spatial stats (polsby_popper, num_geom_parts) for the
            // new district.  createDistrict() sets seats/population inline above but
            // does not run recomputeDistrict() for the new record — call it here now
            // that all member junctions are inserted and the transaction is still open.
            $this->recomputeDistrict($districtId, $legislature_id, $leg);

            DB::commit();

            // Recompute 4-color assignment for all districts at this scope
            $this->recomputeColorIndices($legislature_id, $scopeId, $leg->jurisdiction_id, $mapId);

            $newDistrict = DB::table('legislature_districts')->where('id', $districtId)->first();

            // Compute district name — scope-aware (root: codes only; sub-scope: scope code + number).
            // Uses $labelScopeId (first child of root) so grandchild scopes label correctly.
            $isRootScope = ($labelScopeId === $leg->jurisdiction_id);
            if ($isRootScope) {
                $memberCodes = array_unique($jRows->map(
                    fn($r) => $this->makeShortCode($r->name, $r->iso_code, (int) $r->adm_level)
                )->toArray());
                sort($memberCodes);
                $districtName = $memberCodes ? implode('-', $memberCodes) : 'District';
            } else {
                $labelScopeRow = DB::table('jurisdictions')->where('id', $labelScopeId)->first();
                $scopeCode     = $this->makeShortCode($labelScopeRow->name, $labelScopeRow->iso_code, (int) $labelScopeRow->adm_level);
                $numPadded     = str_pad($districtNumber, 2, '0', STR_PAD_LEFT);
                $districtName  = $scopeCode . ' ' . $numPadded;
            }

            // Fetch updated data for districts that lost members (so frontend can refresh their seat counts)
            $affectedDistrictsData = [];
            foreach ($existingDistrictIds as $affId) {
                $affRow = DB::table('legislature_districts')->where('id', $affId)->whereNull('deleted_at')->first();
                if ($affRow) {
                    $affectedDistrictsData[] = [
                        'id'           => $affRow->id,
                        'seats'        => (int) $affRow->seats,
                        'floor_override' => (bool) $affRow->floor_override,
                        'color_index'  => (int) $affRow->color_index,
                    ];
                }
            }

            $this->flushRevealedCache($legislature_id, $mapId, $scopeId);

            // Return color_index for every district in this legislature+map so the
            // frontend can sync neighbor colors without a full page refresh.
            $colorUpdatesQuery = DB::table('legislature_districts')
                ->where('legislature_id', $legislature_id)
                ->whereNull('deleted_at');
            if ($mapId !== null) {
                $colorUpdatesQuery->where('map_id', $mapId);
            }
            $colorUpdates = $colorUpdatesQuery->pluck('color_index', 'id');

            return response()->json([
                'district' => [
                    'id'              => $newDistrict->id,
                    'seats'           => (int) $newDistrict->seats,
                    'floor_override'  => (bool) $newDistrict->floor_override,
                    'color_index'     => (int) $newDistrict->color_index,
                    'status'          => $newDistrict->status,
                    'member_count'    => count($jids),
                    'district_number' => $districtNumber,
                    'name'            => $districtName,
                ],
                'affected_districts' => $affectedDistrictsData,
                'color_updates'      => $colorUpdates,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create district: ' . $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /api/legislatures/{legislature_id}/districts/{district_id}/members
     *
     * Move jurisdictions into or out of a district.
     * Body: { add: [uuid, ...], remove: [uuid, ...] }
     */
    public function updateDistrictMembers(Request $request, string $legislature_id, string $district_id): JsonResponse
    {
        $leg = DB::table('legislatures')->where('id', $legislature_id)->whereNull('deleted_at')->first();
        if (!$leg) return response()->json(['error' => 'Legislature not found'], 404);

        $district = DB::table('legislature_districts')
            ->where('id', $district_id)
            ->where('legislature_id', $legislature_id)
            ->whereNull('deleted_at')
            ->first();
        if (!$district) return response()->json(['error' => 'District not found'], 404);

        $add          = $request->input('add', []);
        $remove       = $request->input('remove', []);
        $reqScopeId   = $request->input('scope_id');
        // label_scope_id: first child of root (used for naming at grandchild scopes).
        $labelScopeId = $request->input('label_scope_id', $reqScopeId);

        // Pre-validate composite sum: compute projected membership after add/remove
        if (!empty($add)) {
            // Compute local quota for this district's scope
            $distScopeId  = DB::table('legislature_districts')->where('id', $district_id)->value('jurisdiction_id')
                            ?? $leg->jurisdiction_id;
            $distRootPop  = max((int) DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('population'), 1);
            $distScopeRow = DB::table('jurisdictions')->where('id', $distScopeId)->whereNull('deleted_at')->first();
            $distScopePop = (int) DB::table('jurisdictions')
                ->where('parent_id', $distScopeId)
                ->whereNull('deleted_at')
                ->sum('population');
            if ($distScopeId === $leg->jurisdiction_id) {
                $distSeatBudget = (int) $leg->type_a_seats;
            } elseif ($distScopeRow && $distScopeRow->type_a_apportioned !== null) {
                $distSeatBudget = (int) $distScopeRow->type_a_apportioned;
            } else {
                $distSeatBudget = max(5, (int) round((int) ($distScopeRow ? $distScopeRow->population : 0) * (int) $leg->type_a_seats / $distRootPop));
            }
            $localQuota = $distScopePop / max($distSeatBudget, 1);

            // Validate no added jurisdiction is a giant
            $addRows = DB::table('jurisdictions')->whereIn('id', $add)->get(['id', 'name', 'population']);
            foreach ($addRows as $aRow) {
                $frac = (int) $aRow->population / max($localQuota, 1);
                if ($frac >= 9.5) {
                    return response()->json([
                        'error' => "{$aRow->name} has " . number_format($frac, 2) . " fractional seats (≥ 9.5). " .
                                   "Giant jurisdictions cannot be composited — drill down instead.",
                    ], 422);
                }
            }

            // Compute post-edit total fractional
            $existingPop = (int) DB::table('legislature_district_jurisdictions as ldj')
                ->join('jurisdictions as j', 'j.id', '=', 'ldj.jurisdiction_id')
                ->where('ldj.district_id', $district_id)
                ->whereNotIn('ldj.jurisdiction_id', $remove)
                ->sum('j.population');
            $addPop      = (int) $addRows->sum('population');
            $projectedFrac = ($existingPop + $addPop) / max($localQuota, 1);

            if ($projectedFrac >= 9.5) {
                return response()->json([
                    'error' => sprintf(
                        'Projected composite fractional seats (%.2f) ≥ 9.5 — would round to ≥ 10, ' .
                        'exceeding the constitutional district maximum of 9.',
                        $projectedFrac
                    ),
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            $affectedDistrictIds = [];

            if (!empty($remove)) {
                DB::table('legislature_district_jurisdictions')
                    ->where('district_id', $district_id)
                    ->whereIn('jurisdiction_id', $remove)
                    ->delete();
            }

            if (!empty($add)) {
                // Remove these jids from any other district in the same legislature + map
                $srcQuery = DB::table('legislature_district_jurisdictions as ldj')
                    ->join('legislature_districts as ld', 'ld.id', '=', 'ldj.district_id')
                    ->where('ld.legislature_id', $legislature_id)
                    ->where('ld.id', '!=', $district_id)
                    ->whereIn('ldj.jurisdiction_id', $add);
                if ($district->map_id !== null) {
                    $srcQuery->where('ld.map_id', $district->map_id);
                }
                $srcDistrictIds = $srcQuery->pluck('ld.id')->unique()->values()->toArray();

                $affectedDistrictIds = array_merge($affectedDistrictIds, $srcDistrictIds);

                $delStealQuery = DB::table('legislature_district_jurisdictions')
                    ->join('legislature_districts', 'legislature_districts.id', '=', 'legislature_district_jurisdictions.district_id')
                    ->where('legislature_districts.legislature_id', $legislature_id)
                    ->where('legislature_districts.id', '!=', $district_id)
                    ->whereIn('legislature_district_jurisdictions.jurisdiction_id', $add);
                if ($district->map_id !== null) {
                    $delStealQuery->where('legislature_districts.map_id', $district->map_id);
                }
                $delStealQuery->delete();

                $memberships = array_map(fn($jid) => [
                    'id'              => (string) \Illuminate\Support\Str::uuid(),
                    'district_id'     => $district_id,
                    'jurisdiction_id' => $jid,
                ], $add);

                DB::table('legislature_district_jurisdictions')->insert($memberships);
            }

            // Recompute this district + affected source districts
            $this->recomputeDistrict($district_id, $legislature_id, $leg);
            foreach (array_unique($affectedDistrictIds) as $affectedId) {
                $this->recomputeDistrict($affectedId, $legislature_id, $leg);
            }

            DB::commit();

            // Recompute 4-color assignment for all districts at this scope
            $this->recomputeColorIndices($legislature_id, $district->jurisdiction_id, $leg->jurisdiction_id, $district->map_id);

            $updated = DB::table('legislature_districts')->where('id', $district_id)->first();
            $memberCount = DB::table('legislature_district_jurisdictions')
                ->where('district_id', $district_id)->count();

            // Compute updated district name — scope-aware, using labelScopeId (first child of root).
            $isRootScope = (!$labelScopeId || $labelScopeId === $leg->jurisdiction_id);
            if ($isRootScope) {
                $memberRows  = DB::table('legislature_district_jurisdictions as ldj')
                    ->join('jurisdictions as j', 'j.id', '=', 'ldj.jurisdiction_id')
                    ->where('ldj.district_id', $district_id)
                    ->get(['j.name', 'j.iso_code', 'j.adm_level']);
                $memberCodes = array_unique($memberRows->map(
                    fn($r) => $this->makeShortCode($r->name, $r->iso_code, (int) $r->adm_level)
                )->toArray());
                sort($memberCodes);
                $districtName = $memberCodes ? implode('-', $memberCodes) : 'District';
            } else {
                $labelScopeRow = DB::table('jurisdictions')->where('id', $labelScopeId)->first();
                $scopeCode     = $this->makeShortCode($labelScopeRow->name, $labelScopeRow->iso_code, (int) $labelScopeRow->adm_level);
                $numPadded     = str_pad((int) $updated->district_number, 2, '0', STR_PAD_LEFT);
                $districtName  = $scopeCode . ' ' . $numPadded;
            }

            // Fetch updated data for districts that lost members (so frontend can refresh their seat counts)
            $affectedDistrictsData = [];
            foreach (array_unique($affectedDistrictIds) as $affId) {
                $affRow = DB::table('legislature_districts')->where('id', $affId)->whereNull('deleted_at')->first();
                if ($affRow) {
                    $affectedDistrictsData[] = [
                        'id'           => $affRow->id,
                        'seats'        => (int) $affRow->seats,
                        'floor_override' => (bool) $affRow->floor_override,
                        'color_index'  => (int) $affRow->color_index,
                    ];
                }
            }

            $resolvedMapId = $this->getMapId($legislature_id, $district->map_id);
            $this->flushRevealedCache($legislature_id, $resolvedMapId, $district->jurisdiction_id);

            return response()->json([
                'district' => [
                    'id'              => $updated->id,
                    'seats'           => (int) $updated->seats,
                    'floor_override'  => (bool) $updated->floor_override,
                    'color_index'     => (int) $updated->color_index,
                    'status'          => $updated->status,
                    'member_count'    => $memberCount,
                    'district_number' => (int) $updated->district_number,
                    'name'            => $districtName,
                ],
                'affected_districts' => $affectedDistrictsData,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to update district: ' . $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/legislatures/{legislature_id}/districts/{district_id}
     *
     * Soft-delete the district; hard-delete its jurisdiction memberships.
     */
    public function deleteDistrict(string $legislature_id, string $district_id): JsonResponse
    {
        $district = DB::table('legislature_districts')
            ->where('id', $district_id)
            ->where('legislature_id', $legislature_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$district) {
            return response()->json(['error' => 'District not found'], 404);
        }

        $leg = DB::table('legislatures')
            ->where('id', $legislature_id)
            ->whereNull('deleted_at')
            ->first();

        $scopeId = $district->jurisdiction_id ?? ($leg ? $leg->jurisdiction_id : null);
        $distMapId = $this->getMapId($legislature_id, $district->map_id ?? null);

        DB::table('legislature_district_jurisdictions')->where('district_id', $district_id)->delete();
        DB::table('legislature_districts')->where('id', $district_id)->update(['deleted_at' => now()]);

        // Recompute 4-color assignment for remaining districts at this scope
        if ($scopeId && $leg) {
            $this->recomputeColorIndices($legislature_id, $scopeId, $leg->jurisdiction_id, $distMapId);
        }

        $this->flushRevealedCache($legislature_id, $distMapId, $scopeId);

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/legislatures/{legislature_id}/revealed.geojson?scope={scope_id}
     *
     * Returns district polygons for giant children of scope that have been
     * "broken down" into sub-districts. Used to display sub-district polygons
     * on the Earth-level map instead of a single red giant blob.
     */
    public function revealedGeoJson(Request $request, string $legislature_id): JsonResponse
    {
        $scopeId = $request->query('scope');
        if (!$scopeId) {
            return response()->json(['type' => 'FeatureCollection', 'features' => []]);
        }

        $revMapId   = $this->getMapId($legislature_id, $request->query('map'));
        $revMapFilt = $revMapId !== null ? 'AND ld.map_id = :map_id'  : '';
        $revMapFil2 = $revMapId !== null ? 'AND ld.map_id = :map_id2' : '';
        $revMapBind = $revMapId !== null ? ['map_id' => $revMapId, 'map_id2' => $revMapId] : [];

        // Root pop + total seats — used to enforce the giant threshold (>= 9.5 frac seats)
        // on both depth branches so non-giant scope districts never appear in the revealed layer.
        $revRootPop = max((int) DB::table('jurisdictions')
            ->join('legislatures', 'legislatures.jurisdiction_id', '=', 'jurisdictions.id')
            ->where('legislatures.id', $legislature_id)
            ->value('jurisdictions.population'), 1);
        $revTotalSeats = (int) DB::table('legislatures')
            ->where('id', $legislature_id)
            ->value('type_a_seats');

        $zoom      = (int) $request->query('zoom', 6);
        $tolerance = $this->toleranceForZoom($zoom);
        $tol       = number_format($tolerance, 8, '.', '');
        $mapKey    = $revMapId ?? 'null';
        $cacheKey  = "geojson.revealed.{$legislature_id}.{$scopeId}.{$mapKey}.z{$zoom}";
        $cacheTag  = "revealed.{$legislature_id}.{$mapKey}.{$scopeId}";

        // Broad tag allows recomputeDistrict() to flush all revealed caches for this
        // legislature with a single Cache::tags(["revealed.{$id}"])->flush() call,
        // without needing to know the mapKey or scopeId at write time.
        $payload = Cache::tags([$cacheTag, "revealed.{$legislature_id}"])->remember($cacheKey, 86400, function () use (
            $legislature_id, $scopeId, $revMapFilt, $revMapFil2, $revMapBind,
            $revRootPop, $revTotalSeats, $tol
        ) {

        // Revealed layer returns one GeoJSON feature per constituent jurisdiction per district,
        // using each jurisdiction's own geometry (jurisdictions.geom) rather than a pre-computed
        // district union polygon. This gives full fidelity with zero geometry computation.
        // Same-district features share a color_index; the frontend suppresses their shared borders
        // by setting stroke color = fill color, making only cross-district edges visible.
        //
        // Phase 4 sub-districts store their member jurisdictions in legislature_district_jurisdictions.
        // The members are at ADM2 (states/provinces) or ADM3 (counties) level — NOT at the
        // country level. We walk UP from each member to find its giant Earth-child ancestor.
        //
        // Branch 1: members at ADM2 — member.parent_id IS the giant country (Earth-direct-child).
        // Branch 2: members at ADM3 — member.parent_id is a state; state.parent_id is the giant.
        //
        // Giant threshold: country fractional seats = country_pop * total_seats / root_pop >= 9.5
        // (same as the 9-seat cap that triggers Phase 4 splitting in the ETL).
        //
        // UNION ALL is used because PDO named params cannot be reused across branches;
        // :leg_id2 / :scope_id2 / :total_seats2 / :root_pop2 are aliases for the same values.
        $rows = DB::select("
            -- Branch 1: ADM2-level members whose direct parent is a giant Earth-child
            SELECT
                ld.id            AS district_id,
                ld.seats,
                ld.color_index,
                ld.floor_override,
                j_giant.id       AS parent_jurisdiction_id,
                j_giant.name     AS parent_name,
                ld.district_number,
                j_giant.iso_code      AS parent_iso_code,
                j_giant.adm_level     AS parent_adm_level,
                j_gp.iso_code         AS grandparent_iso_code,
                j_gp.adm_level        AS grandparent_adm_level,
                j_gp.name             AS grandparent_name,
                (j_gp.parent_id IS NULL) AS grandparent_is_root,
                j_giant.id            AS giant_jurisdiction_id,
                ld.actual_population  AS district_population,
                ld.fractional_seats   AS district_fractional_seats,
                ld.convex_hull_ratio,
                ld.is_contiguous,
                j_member.id        AS jurisdiction_id,
                j_member.name      AS member_name,
                j_member.iso_code  AS member_iso_code,
                j_member.adm_level AS member_adm_level,
                ST_AsGeoJSON(ST_Simplify(j_member.geom, {$tol})) AS geojson
            FROM legislature_districts ld
            JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
            JOIN jurisdictions j_member ON j_member.id = ldj.jurisdiction_id
                AND j_member.deleted_at IS NULL
                AND j_member.geom IS NOT NULL
            JOIN jurisdictions j_giant ON j_giant.id = j_member.parent_id
                AND j_giant.parent_id = :scope_id
                AND j_giant.deleted_at IS NULL
                AND (CAST(j_giant.population AS numeric) * :total_seats / :root_pop) >= 9.5
            LEFT JOIN jurisdictions j_gp ON j_gp.id = j_giant.parent_id
            WHERE ld.legislature_id = :leg_id
              AND ld.deleted_at IS NULL
              {$revMapFilt}

            UNION ALL

            -- Branch 2: ADM3-level members whose grandparent is a giant Earth-child.
            -- parent = j_state (e.g. California), grandparent = j_giant (e.g. USA).
            -- This produces USA CAL 01 at Earth scope: grandparent=USA (not root since
            -- USA.parent_id = Earth != NULL) + parent=California -> USA + CAL + 01.
            SELECT
                ld.id,
                ld.seats,
                ld.color_index,
                ld.floor_override,
                j_state.id,
                j_state.name,
                ld.district_number,
                j_state.iso_code,
                j_state.adm_level,
                j_giant.iso_code,
                j_giant.adm_level,
                j_giant.name,
                (j_giant.parent_id IS NULL),
                j_giant.id,
                ld.actual_population,
                ld.fractional_seats,
                ld.convex_hull_ratio,
                ld.is_contiguous,
                j_member.id,
                j_member.name,
                j_member.iso_code,
                j_member.adm_level,
                ST_AsGeoJSON(ST_Simplify(j_member.geom, {$tol}))
            FROM legislature_districts ld
            JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
            JOIN jurisdictions j_member ON j_member.id = ldj.jurisdiction_id
                AND j_member.deleted_at IS NULL
                AND j_member.geom IS NOT NULL
            JOIN jurisdictions j_state ON j_state.id = j_member.parent_id
                AND j_state.deleted_at IS NULL
            JOIN jurisdictions j_giant ON j_giant.id = j_state.parent_id
                AND j_giant.parent_id = :scope_id2
                AND j_giant.deleted_at IS NULL
                AND (CAST(j_giant.population AS numeric) * :total_seats2 / :root_pop2) >= 9.5
            WHERE ld.legislature_id = :leg_id2
              AND ld.deleted_at IS NULL
              {$revMapFil2}
        ", array_merge([
            'leg_id'       => $legislature_id,
            'scope_id'     => $scopeId,
            'total_seats'  => $revTotalSeats,
            'root_pop'     => $revRootPop,
            'leg_id2'      => $legislature_id,
            'scope_id2'    => $scopeId,
            'total_seats2' => $revTotalSeats,
            'root_pop2'    => $revRootPop,
        ], $revMapBind));

        $features = [];
        foreach ($rows as $row) {
            if (!$row->geojson) continue;
            $features[] = [
                'type'     => 'Feature',
                'id'       => $row->jurisdiction_id,
                'geometry' => json_decode($row->geojson),
                'properties' => [
                    'district_id'            => $row->district_id,
                    'jurisdiction_id'        => $row->jurisdiction_id,
                    'parent_jurisdiction_id' => $row->parent_jurisdiction_id,
                    'parent_name'            => $row->parent_name,
                    'seats'                  => (int) $row->seats,
                    'color_index'            => (int) $row->color_index,
                    'floor_override'         => (bool) $row->floor_override,
                    'district_number'           => (int) $row->district_number,
                    'parent_iso_code'           => $row->parent_iso_code,
                    'parent_adm_level'          => (int) $row->parent_adm_level,
                    'grandparent_iso_code'      => $row->grandparent_iso_code,
                    'grandparent_adm_level'     => $row->grandparent_adm_level !== null ? (int) $row->grandparent_adm_level : null,
                    'grandparent_name'          => $row->grandparent_name,
                    'grandparent_is_root'       => (bool) ($row->grandparent_is_root ?? false),
                    'giant_jurisdiction_id'     => $row->giant_jurisdiction_id,
                    'district_population'       => (int) $row->district_population,
                    'district_fractional_seats' => (float) $row->district_fractional_seats,
                    'convex_hull_ratio'         => $row->convex_hull_ratio !== null ? round((float) $row->convex_hull_ratio, 3) : null,
                    'is_contiguous'             => $row->is_contiguous !== null ? (bool) $row->is_contiguous : null,
                    'member_name'               => $row->member_name,
                    'member_iso_code'           => $row->member_iso_code,
                    'member_adm_level'          => $row->member_adm_level !== null ? (int) $row->member_adm_level : null,
                    'type'                      => 'sub_district',
                ],
            ];
        }

        // Parent outline features — giant country borders (depth 1, solid) and sub-giant
        // state borders (depth 2, dashed) drawn on top of the revealed sub-district fill.
        //
        // Branch A (depth 1): giant countries found via ADM2-level members
        // Branch B (depth 1): giant countries found via ADM3-level members (deduped by UNION)
        // Branch C (depth 2): ADM2 state boundaries that contain ADM3-level members —
        //   these are "sub-giants" within a giant country, drawn as dashed outlines to show
        //   how the giant's budget is further sub-divided into state-level groupings.
        //   depth 2 → dashArray '5 5' in the Vue outline layer style.
        $outlineRows = DB::select("
            -- Branch A: depth-1 giant country outlines via ADM2 members
            SELECT DISTINCT
                j_giant.id   AS jurisdiction_id,
                j_giant.name AS parent_name,
                ST_AsGeoJSON(ST_Simplify(j_giant.geom, {$tol})) AS geojson,
                1 AS depth
            FROM legislature_districts ld
            JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
            JOIN jurisdictions j_member ON j_member.id = ldj.jurisdiction_id
                AND j_member.deleted_at IS NULL
            JOIN jurisdictions j_giant ON j_giant.id = j_member.parent_id
                AND j_giant.parent_id = :scope_id_o
                AND j_giant.deleted_at IS NULL
                AND j_giant.geom IS NOT NULL
                AND (CAST(j_giant.population AS numeric) * :total_seats_o / :root_pop_o) >= 9.5
            WHERE ld.legislature_id = :leg_id_o
              AND ld.deleted_at IS NULL

            UNION

            -- Branch B: depth-1 giant country outlines via ADM3 members (deduped)
            SELECT DISTINCT
                j_giant.id,
                j_giant.name,
                ST_AsGeoJSON(ST_Simplify(j_giant.geom, {$tol})),
                1
            FROM legislature_districts ld
            JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
            JOIN jurisdictions j_member ON j_member.id = ldj.jurisdiction_id
                AND j_member.deleted_at IS NULL
            JOIN jurisdictions j_state ON j_state.id = j_member.parent_id
                AND j_state.deleted_at IS NULL
            JOIN jurisdictions j_giant ON j_giant.id = j_state.parent_id
                AND j_giant.parent_id = :scope_id_o2
                AND j_giant.deleted_at IS NULL
                AND j_giant.geom IS NOT NULL
                AND (CAST(j_giant.population AS numeric) * :total_seats_o2 / :root_pop_o2) >= 9.5
            WHERE ld.legislature_id = :leg_id_o2
              AND ld.deleted_at IS NULL

            UNION

            -- Branch C: depth-2 state outlines (ADM2 parents of ADM3 members within giants)
            SELECT DISTINCT
                j_state.id,
                j_state.name,
                ST_AsGeoJSON(ST_Simplify(j_state.geom, {$tol})) AS geojson,
                2 AS depth
            FROM legislature_districts ld
            JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
            JOIN jurisdictions j_member ON j_member.id = ldj.jurisdiction_id
                AND j_member.deleted_at IS NULL
            JOIN jurisdictions j_state ON j_state.id = j_member.parent_id
                AND j_state.deleted_at IS NULL
                AND j_state.geom IS NOT NULL
            JOIN jurisdictions j_giant ON j_giant.id = j_state.parent_id
                AND j_giant.parent_id = :scope_id_o3
                AND j_giant.deleted_at IS NULL
                AND (CAST(j_giant.population AS numeric) * :total_seats_o3 / :root_pop_o3) >= 9.5
            WHERE ld.legislature_id = :leg_id_o3
              AND ld.deleted_at IS NULL
        ", [
            'leg_id_o'       => $legislature_id,
            'scope_id_o'     => $scopeId,
            'total_seats_o'  => $revTotalSeats,
            'root_pop_o'     => $revRootPop,
            'leg_id_o2'      => $legislature_id,
            'scope_id_o2'    => $scopeId,
            'total_seats_o2' => $revTotalSeats,
            'root_pop_o2'    => $revRootPop,
            'leg_id_o3'      => $legislature_id,
            'scope_id_o3'    => $scopeId,
            'total_seats_o3' => $revTotalSeats,
            'root_pop_o3'    => $revRootPop,
        ]);

        $outlineFeatures = [];
        foreach ($outlineRows as $row) {
            if (!$row->geojson) continue;
            $outlineFeatures[] = [
                'type'     => 'Feature',
                'id'       => 'outline_' . $row->jurisdiction_id,
                'geometry' => json_decode($row->geojson),
                'properties' => [
                    'type'            => 'parent_outline',
                    'jurisdiction_id' => $row->jurisdiction_id,
                    'parent_name'     => $row->parent_name,
                    'depth'           => (int) $row->depth,
                ],
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => array_merge($features, $outlineFeatures)];
        }); // end Cache::remember

        // no-store prevents browser HTTP caching — staleness is handled by the Redis
        // version counter, but the browser must always hit the server to check the key.
        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    /**
     * POST /api/legislatures/{legislature_id}/auto-composite
     *
     * Automatically groups non-giant children of scope into compact,
     * contiguous, balanced districts using BFS adjacency + balanced LPT.
     *
     * Body: { scope_id: uuid, clear_existing: bool }
     */
    public function autoComposite(Request $request, string $legislature_id): JsonResponse
    {
        $leg = DB::table('legislatures')
            ->where('id', $legislature_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$leg) {
            return response()->json(['error' => 'Legislature not found'], 404);
        }

        $scopeId       = $request->input('scope_id');
        $clearExisting = (bool) $request->input('clear_existing', false);
        $mapId         = $this->getMapId($legislature_id, $request->input('map_id'));

        if (!$scopeId) {
            return response()->json(['error' => 'scope_id is required'], 422);
        }

        // At root scope: auto-update type_a_seats from cube root of sum(children populations).
        // At non-root scope: read type_a_apportioned set by parent scope's autoComposite.
        $isAutoRoot = ($scopeId === $leg->jurisdiction_id);
        DB::beginTransaction();
        try {
            if ($isAutoRoot) {
                $sumChildPop = (int) DB::table('jurisdictions')
                    ->where('parent_id', $scopeId)
                    ->whereNull('deleted_at')
                    ->sum('population');
                $newSeats = max(5, (int) round(pow(max($sumChildPop, 1), 1/3)));
                if ((int) $leg->type_a_seats !== $newSeats) {
                    DB::table('legislatures')->where('id', $legislature_id)->update(['type_a_seats' => $newSeats]);
                    $leg->type_a_seats = $newSeats;
                }
                $seatBudget = (int) $leg->type_a_seats;
            } else {
                $autoScope   = DB::table('jurisdictions')->where('id', $scopeId)->whereNull('deleted_at')->first();
                $autoRootPop = max((int) DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('population'), 1);
                $seatBudget  = $autoScope && $autoScope->type_a_apportioned !== null
                    ? (int) $autoScope->type_a_apportioned
                    : max(5, (int) round((int) ($autoScope ? $autoScope->population : 0) * (int) $leg->type_a_seats / $autoRootPop));
            }

            $result = $this->runAutoCompositeForScope(
                $legislature_id, $leg, $scopeId, $clearExisting, $seatBudget, $mapId
            );
            if ($result['error'] !== null) {
                DB::rollBack();
                return response()->json(['error' => $result['error']], 422);
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Auto-composite failed: ' . $e->getMessage()], 500);
        }

        $this->recomputeColorIndices($legislature_id, $scopeId, $leg->jurisdiction_id, $mapId);

        // Invalidate revealed.geojson cache — autoComposite creates/replaces districts.
        $this->flushRevealedCache($legislature_id, $mapId, $scopeId);

        return response()->json([
            'success'          => true,
            'districts_created'=> $result['districts_created'],
        ]);
    }

    /**
     * POST /api/legislatures/{legislature_id}/mass-reseed
     *
     * Run auto-composite across one or many scopes.
     *
     * Body:
     *   operation_scope: string  — one of six keys (see MASS_SCOPES in frontend)
     *   scope_id:        uuid    — current map-view jurisdiction (used for map_view_* and
     *                              map_plus_children_* scopes, and as a seed for legislature_unassigned)
     *
     * Six operation_scope values:
     *   map_view_unassigned          — this scope only, clear_existing=false
     *   map_view_all                 — this scope only, clear_existing=true
     *   map_plus_children_unassigned — this scope + giant children, clear_existing=false
     *   map_plus_children_all        — this scope + giant children, clear_existing=true
     *   legislature_unassigned       — all scopes with existing districts + current scope, clear=false
     *   legislature_all              — all scopes with existing districts, clear_existing=true
     */
    public function massReseed(Request $request, string $legislature_id): JsonResponse
    {
        $leg = DB::table('legislatures')
            ->where('id', $legislature_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$leg) {
            return response()->json(['error' => 'Legislature not found'], 404);
        }

        $operationScope = $request->input('operation_scope');
        $scopeId        = $request->input('scope_id');
        $mapId          = $this->getMapId($legislature_id, $request->input('map_id'));

        if (!$operationScope || !$scopeId) {
            return response()->json(['error' => 'operation_scope and scope_id are required'], 422);
        }

        // Mark this legislature as having an active mass operation (for UI progress indicator)
        Cache::put("legislature.{$legislature_id}.mass_running", true, 7200);

        // Derive clear_existing from the scope name: _all → true, _unassigned → false
        $clearExisting = str_ends_with($operationScope, '_all');

        $rootPop = max((int) DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('population'), 1);

        // At root scope: auto-update type_a_seats from cube root of children sum.
        if ($scopeId === $leg->jurisdiction_id) {
            $sumChildPop = (int) DB::table('jurisdictions')
                ->where('parent_id', $scopeId)
                ->whereNull('deleted_at')
                ->sum('population');
            $newSeats = max(5, (int) round(pow(max($sumChildPop, 1), 1/3)));
            if ((int) $leg->type_a_seats !== $newSeats) {
                DB::table('legislatures')->where('id', $legislature_id)->update(['type_a_seats' => $newSeats]);
                $leg->type_a_seats = $newSeats;
            }
        }

        $rootQuota = $rootPop / max((int) $leg->type_a_seats, 1);

        $scopeIds = $this->resolveMassScopeIds(
            $legislature_id, $leg, $scopeId, $operationScope, $rootQuota, $mapId
        );

        $totalCreated   = 0;
        $scopesProcessed = 0;
        $errors          = [];

        DB::beginTransaction();
        try {
            foreach ($scopeIds as $sid) {
                // Compute per-scope seat budget
                if ($sid === $leg->jurisdiction_id) {
                    $seatBudget = (int) $leg->type_a_seats;
                } else {
                    $sidScope    = DB::table('jurisdictions')->where('id', $sid)->whereNull('deleted_at')->first();
                    // Use SUM(direct_children.population) for the proportional fallback — matches show()
                    // level-by-level apportionment fix (2026-03-10). Stored population can differ from
                    // the children sum due to ADM-level data-source inconsistencies in GeoBoundaries.
                    $sidChildPop = (int) DB::table('jurisdictions')
                        ->where('parent_id', $sid)
                        ->whereNull('deleted_at')
                        ->sum('population');
                    $seatBudget  = $sidScope && $sidScope->type_a_apportioned !== null
                        ? (int) $sidScope->type_a_apportioned
                        : max(5, (int) round($sidChildPop * (int) $leg->type_a_seats / $rootPop));
                }
                $result = $this->runAutoCompositeForScope(
                    $legislature_id, $leg, $sid, $clearExisting, $seatBudget, $mapId
                );
                if ($result['error'] !== null) {
                    // Non-fatal (e.g. no compositable children) — record and continue
                    $errors[] = $result['error'];
                } else {
                    $totalCreated    += $result['districts_created'];
                    $scopesProcessed++;
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Cache::forget("legislature.{$legislature_id}.mass_running");
            return response()->json(['error' => 'Mass reseed failed: ' . $e->getMessage()], 500);
        }

        // Recompute 4-color indices outside the transaction (read-heavy, non-critical to atomicity)
        foreach ($scopeIds as $sid) {
            $this->recomputeColorIndices($legislature_id, $sid, $leg->jurisdiction_id, $mapId);
        }

        Cache::forget("legislature.{$legislature_id}.mass_running");
        $this->flushRevealedCache($legislature_id, $mapId, $scopeId);

        // Auto-queue adjacency recolor after any bulk seed so colors are always correct
        // without requiring a manual Recolor click.
        \App\Jobs\RecolorDistrictsJob::dispatch($legislature_id, $mapId);

        return response()->json([
            'success'          => true,
            'districts_created'=> $totalCreated,
            'scopes_processed' => $scopesProcessed,
            'errors'           => $errors,
        ]);
    }

    /**
     * POST /api/legislatures/{legislature_id}/mass-disband
     *
     * Hard-delete all districts (and memberships) across one or many scopes.
     * Giants are unaffected. Only _all variants are meaningful here.
     *
     * Body:
     *   operation_scope: string  — map_view_all | map_plus_children_all | legislature_all
     *   scope_id:        uuid    — current map-view jurisdiction
     */
    public function massDisband(Request $request, string $legislature_id): JsonResponse
    {
        $leg = DB::table('legislatures')
            ->where('id', $legislature_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$leg) {
            return response()->json(['error' => 'Legislature not found'], 404);
        }

        $operationScope = $request->input('operation_scope');
        $scopeId        = $request->input('scope_id');
        $mapId          = $this->getMapId($legislature_id, $request->input('map_id'));

        if (!$operationScope || !$scopeId) {
            return response()->json(['error' => 'operation_scope and scope_id are required'], 422);
        }

        Cache::put("legislature.{$legislature_id}.mass_running", true, 7200);

        $rootPop   = (int) DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('population');
        $rootQuota = max($rootPop, 1) / max((int) $leg->type_a_seats, 1);

        $scopeIds = $this->resolveMassScopeIds(
            $legislature_id, $leg, $scopeId, $operationScope, $rootQuota, $mapId
        );

        // For map_view_* disband operations, also cascade to giant direct children of the scope.
        // Giant children render their sub-districts visually ON the current map view (their colored
        // polygons are what the user sees). When the user selects "Clear all at this map view" they
        // expect every colored polygon to disappear — not just the ones whose district record sits at
        // exactly jurisdiction_id=$scopeId. Cascading to giant children makes the disband match the
        // visual expectation without changing reseed behavior (massReseed keeps map_view_* scoped to
        // this level only so it doesn't over-seed giant sub-scopes).
        if (str_starts_with($operationScope, 'map_view_')) {
            $giantChildIds = DB::table('jurisdictions')
                ->where('parent_id', $scopeId)
                ->whereNull('deleted_at')
                ->get(['id', 'population'])
                ->filter(fn($j) => ((int) $j->population / max($rootQuota, 1)) >= 9.5)
                ->pluck('id')
                ->toArray();
            if (!empty($giantChildIds)) {
                $scopeIds = array_unique(array_merge($scopeIds, $giantChildIds));
            }
        }

        // Also include non-giant direct child scopes that have existing districts in this map.
        // These are ETL artifacts — non-giants should never have sub-scope districts.
        // Unconditionally safe to clear: constitutionally they cannot hold districts.
        $nonGiantQuery = DB::table('jurisdictions AS j')
            ->join('legislature_districts AS ld', 'ld.jurisdiction_id', '=', 'j.id')
            ->whereIn('j.parent_id', $scopeIds)
            ->whereNull('j.deleted_at')
            ->where('ld.legislature_id', $legislature_id)
            ->whereNull('ld.deleted_at')
            ->whereRaw('(CAST(j.population AS numeric) / ?) < 9.5', [$rootQuota]);
        if ($mapId !== null) {
            $nonGiantQuery->where('ld.map_id', $mapId);
        }
        $nonGiantChildIds = $nonGiantQuery->distinct()->pluck('j.id')->toArray();

        if (!empty($nonGiantChildIds)) {
            $scopeIds = array_unique(array_merge($scopeIds, $nonGiantChildIds));
        }

        $totalDisbanded  = 0;
        $scopesProcessed = 0;

        DB::beginTransaction();
        try {
            foreach ($scopeIds as $sid) {
                // Hard-delete all districts at this scope in this map (any deleted_at state — clean slate)
                $districtIdsQuery = DB::table('legislature_districts')
                    ->where('legislature_id', $legislature_id)
                    ->where('jurisdiction_id', $sid);
                if ($mapId !== null) {
                    $districtIdsQuery->where('map_id', $mapId);
                }
                $districtIds = $districtIdsQuery->pluck('id')->toArray();

                foreach ($districtIds as $did) {
                    DB::table('legislature_district_jurisdictions')->where('district_id', $did)->delete();
                    DB::table('legislature_districts')->where('id', $did)->delete();
                    $totalDisbanded++;
                }

                // Delete null-jurisdiction composites (Phase 4 ETL artifacts) whose members
                // are direct children of $sid.  Phase 4 composites store jurisdiction_id=NULL;
                // their member jurisdictions have parent_id = the giant country scope.
                $nullIdsQuery = DB::table('legislature_districts AS ld')
                    ->join('legislature_district_jurisdictions AS ldj', 'ldj.district_id', '=', 'ld.id')
                    ->join('jurisdictions AS j', 'j.id', '=', 'ldj.jurisdiction_id')
                    ->where('ld.legislature_id', $legislature_id)
                    ->whereNull('ld.jurisdiction_id')
                    ->where('j.parent_id', $sid)
                    ->whereNull('j.deleted_at');
                if ($mapId !== null) {
                    $nullIdsQuery->where('ld.map_id', $mapId);
                }
                $nullIds = $nullIdsQuery->distinct()->pluck('ld.id')->toArray();
                foreach ($nullIds as $did) {
                    DB::table('legislature_district_jurisdictions')->where('district_id', $did)->delete();
                    DB::table('legislature_districts')->where('id', $did)->delete();
                    $totalDisbanded++;
                }

                // Delete phase1_complete stubs created by ApportionmentSeedCommand.
                // Those records have jurisdiction_id = CHILD jurisdiction id (not scope id),
                // so they are missed by the jurisdiction_id=$sid filter above.
                $childJids = DB::table('jurisdictions')
                    ->where('parent_id', $sid)
                    ->whereNull('deleted_at')
                    ->pluck('id')
                    ->toArray();
                if (!empty($childJids)) {
                    $phase1Query = DB::table('legislature_districts')
                        ->where('legislature_id', $legislature_id)
                        ->whereIn('jurisdiction_id', $childJids)
                        ->where('status', 'phase1_complete');
                    if ($mapId !== null) {
                        $phase1Query->where('map_id', $mapId);
                    }
                    $phase1Ids = $phase1Query->pluck('id')->toArray();
                    foreach ($phase1Ids as $did) {
                        DB::table('legislature_district_jurisdictions')->where('district_id', $did)->delete();
                        DB::table('legislature_districts')->where('id', $did)->delete();
                        $totalDisbanded++;
                    }
                }

                $scopesProcessed++;
            }

            // For legislature_* scopes: explicitly delete all null-jurisdiction composites
            // in this map so they don't silently survive a "Clear — entire legislature" operation.
            if (str_starts_with($operationScope, 'legislature_')) {
                $nullLegQuery = DB::table('legislature_districts')
                    ->where('legislature_id', $legislature_id)
                    ->whereNull('jurisdiction_id');
                if ($mapId !== null) {
                    $nullLegQuery->where('map_id', $mapId);
                }
                $nullIds = $nullLegQuery->pluck('id');
                foreach ($nullIds as $did) {
                    DB::table('legislature_district_jurisdictions')->where('district_id', $did)->delete();
                    DB::table('legislature_districts')->where('id', $did)->delete();
                    $totalDisbanded++;
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Cache::forget("legislature.{$legislature_id}.mass_running");
            return response()->json(['error' => 'Mass disband failed: ' . $e->getMessage()], 500);
        }

        Cache::forget("legislature.{$legislature_id}.mass_running");
        $this->flushRevealedCache($legislature_id, $mapId, $scopeId);

        return response()->json([
            'success'          => true,
            'districts_deleted'=> $totalDisbanded,
            'scopes_processed' => $scopesProcessed,
        ]);
    }

    /**
     * POST /api/legislatures/{legislature_id}/recolor
     *
     * Runs cross-scope greedy 7-coloring across ALL districts in the map and
     * persists the result back to legislature_districts.color_index.
     *
     * Each scope's districts are normally colored independently by the ETL, so
     * adjacent districts belonging to different giant countries can share the same
     * color_index.  This endpoint treats the entire map as a single adjacency graph
     * (using ST_Intersects on member jurisdiction geometries) and assigns the
     * globally-optimal coloring in one pass.  Run once after the map is complete.
     *
     * Body: { map_id?: uuid }
     */
    public function recolor(Request $request, string $legislature_id): JsonResponse
    {
        $leg = DB::table('legislatures')
            ->where('id', $legislature_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$leg) {
            return response()->json(['error' => 'Legislature not found'], 404);
        }

        $mapId = $this->getMapId($legislature_id, $request->input('map_id'));

        // Mark as running so mass-status polling works, then dispatch to queue.
        Cache::put("legislature.{$legislature_id}.mass_running", true, 7200);
        RecolorDistrictsJob::dispatch($legislature_id, $mapId);

        return response()->json(['queued' => true], 202);
    }

    /**
     * GET /api/legislatures/{legislature_id}/mass-status
     *
     * Returns whether a mass reseed/disband operation is currently running.
     * Used by the frontend to poll progress when the user has navigated away.
     */
    public function massStatus(string $legislature_id): JsonResponse
    {
        return response()->json([
            'running'          => (bool) Cache::get("legislature.{$legislature_id}.mass_running", false),
            'recolor_progress' => Cache::get("legislature.{$legislature_id}.recolor_progress"),
        ]);
    }

    // ── District map management ───────────────────────────────────────────────

    /**
     * GET /api/legislatures/{legislature_id}/maps
     *
     * Returns all non-deleted maps for this legislature, ordered newest first.
     * Each row includes a pre-computed district count and flag summary counts.
     */
    public function listMaps(string $legislature_id): JsonResponse
    {
        $maps = DB::table('legislature_district_maps')
            ->where('legislature_id', $legislature_id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();

        $result = [];
        foreach ($maps as $m) {
            $districtCount = DB::table('legislature_districts')
                ->where('legislature_id', $legislature_id)
                ->where('map_id', $m->id)
                ->whereNull('deleted_at')
                ->count();

            $result[] = [
                'id'              => $m->id,
                'name'            => $m->name,
                'description'     => $m->description,
                'status'          => $m->status,
                'effective_start' => $m->effective_start,
                'effective_end'   => $m->effective_end,
                'district_count'  => $districtCount,
                'created_at'      => $m->created_at,
            ];
        }

        return response()->json($result);
    }

    /**
     * POST /api/legislatures/{legislature_id}/maps
     *
     * Body: { name: string, description?: string }
     *
     * Creates a new empty draft map for this legislature.
     */
    public function createMap(Request $request, string $legislature_id): JsonResponse
    {
        $leg = DB::table('legislatures')->where('id', $legislature_id)->whereNull('deleted_at')->first();
        if (!$leg) {
            return response()->json(['error' => 'Legislature not found'], 404);
        }

        $name = trim($request->input('name', ''));
        if ($name === '') {
            return response()->json(['error' => 'name is required'], 422);
        }

        $mapId = (string) Str::uuid();
        DB::table('legislature_district_maps')->insert([
            'id'             => $mapId,
            'legislature_id' => $legislature_id,
            'name'           => substr($name, 0, 120),
            'description'    => $request->input('description'),
            'status'         => 'draft',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json([
            'id'             => $mapId,
            'name'           => $name,
            'status'         => 'draft',
            'district_count' => 0,
            'created_at'     => now()->toIso8601String(),
        ], 201);
    }

    /**
     * PATCH /api/legislatures/{legislature_id}/maps/{map_id}
     *
     * Body: { name?: string, description?: string, effective_start?: date, effective_end?: date }
     *
     * Updates metadata on an existing map.  Status changes go through activateMap().
     */
    public function updateMap(Request $request, string $legislature_id, string $map_id): JsonResponse
    {
        $map = DB::table('legislature_district_maps')
            ->where('id', $map_id)
            ->where('legislature_id', $legislature_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$map) {
            return response()->json(['error' => 'Map not found'], 404);
        }

        $fields = [];
        if ($request->has('name') && trim($request->input('name')) !== '') {
            $fields['name'] = substr(trim($request->input('name')), 0, 120);
        }
        if ($request->has('description')) {
            $fields['description'] = $request->input('description');
        }
        if ($request->has('effective_start')) {
            $fields['effective_start'] = $request->input('effective_start');
        }
        if ($request->has('effective_end')) {
            $fields['effective_end'] = $request->input('effective_end');
        }

        if (!empty($fields)) {
            $fields['updated_at'] = now();
            DB::table('legislature_district_maps')->where('id', $map_id)->update($fields);
        }

        return response()->json(['success' => true]);
    }

    /**
     * DELETE /api/legislatures/{legislature_id}/maps/{map_id}
     *
     * Soft-deletes a map.  Active maps cannot be deleted — deactivate first.
     */
    public function deleteMap(string $legislature_id, string $map_id): JsonResponse
    {
        $map = DB::table('legislature_district_maps')
            ->where('id', $map_id)
            ->where('legislature_id', $legislature_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$map) {
            return response()->json(['error' => 'Map not found'], 404);
        }

        if ($map->status === 'active') {
            return response()->json(['error' => 'Cannot delete the active map. Activate a different map first.'], 422);
        }

        DB::table('legislature_district_maps')
            ->where('id', $map_id)
            ->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/legislatures/{legislature_id}/maps/{map_id}/copy
     *
     * Deep-copies a map: new legislature_district_maps record + all its non-deleted
     * districts + all legislature_district_jurisdictions rows.  New map is always 'draft'.
     */
    public function copyMap(Request $request, string $legislature_id, string $map_id): JsonResponse
    {
        $map = DB::table('legislature_district_maps')
            ->where('id', $map_id)
            ->where('legislature_id', $legislature_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$map) {
            return response()->json(['error' => 'Map not found'], 404);
        }

        $newName  = substr(trim($request->input('name', 'Copy of ' . $map->name)), 0, 120);
        $newMapId = (string) Str::uuid();
        $now      = now();
        $idMap    = [];   // old district id → new district id

        DB::transaction(function () use ($map, $map_id, $legislature_id, $newMapId, $newName, $now, &$idMap) {
            // 1. New map record
            DB::table('legislature_district_maps')->insert([
                'id'             => $newMapId,
                'legislature_id' => $legislature_id,
                'name'           => $newName,
                'description'    => $map->description,
                'status'         => 'draft',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            // 2. Copy all non-deleted districts with new UUIDs
            $districts = DB::table('legislature_districts')
                ->where('map_id', $map_id)
                ->whereNull('deleted_at')
                ->get();

            foreach ($districts as $d) {
                $newDistId     = (string) Str::uuid();
                $idMap[$d->id] = $newDistId;
                DB::table('legislature_districts')->insert([
                    'id'                => $newDistId,
                    'legislature_id'    => $d->legislature_id,
                    'map_id'            => $newMapId,
                    'jurisdiction_id'   => $d->jurisdiction_id,
                    'district_number'   => $d->district_number,
                    'seats'             => $d->seats,
                    'target_population' => $d->target_population,
                    'actual_population' => $d->actual_population,
                    'status'            => $d->status,
                    'floor_override'    => $d->floor_override,
                    'color_index'       => $d->color_index,
                    'fractional_seats'  => $d->fractional_seats,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
            }

            // 3. Copy junction rows for all copied districts
            if (!empty($idMap)) {
                $ldj = DB::table('legislature_district_jurisdictions')
                    ->whereIn('district_id', array_keys($idMap))
                    ->get();

                foreach ($ldj as $row) {
                    DB::table('legislature_district_jurisdictions')->insert([
                        'id'              => (string) Str::uuid(),
                        'district_id'     => $idMap[$row->district_id],
                        'jurisdiction_id' => $row->jurisdiction_id,
                    ]);
                }
            }
        });

        return response()->json([
            'id'             => $newMapId,
            'name'           => $newName,
            'status'         => 'draft',
            'district_count' => count($idMap),
        ], 201);
    }

    /**
     * POST /api/legislatures/{legislature_id}/maps/{map_id}/activate
     *
     * Makes a map the official live apportionment:
     *   - Sets this map's status to 'active', effective_start = today (if null)
     *   - Sets the previous active map (if any) to 'archived', effective_end = today - 1 day
     */
    public function activateMap(string $legislature_id, string $map_id): JsonResponse
    {
        $map = DB::table('legislature_district_maps')
            ->where('id', $map_id)
            ->where('legislature_id', $legislature_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$map) {
            return response()->json(['error' => 'Map not found'], 404);
        }

        DB::transaction(function () use ($legislature_id, $map_id, $map) {
            $today = now()->toDateString();

            // Archive the previous active map
            DB::table('legislature_district_maps')
                ->where('legislature_id', $legislature_id)
                ->where('status', 'active')
                ->where('id', '!=', $map_id)
                ->whereNull('deleted_at')
                ->update([
                    'status'       => 'archived',
                    'effective_end'=> now()->subDay()->toDateString(),
                    'updated_at'   => now(),
                ]);

            // Activate this map
            DB::table('legislature_district_maps')
                ->where('id', $map_id)
                ->update([
                    'status'          => 'active',
                    'effective_start' => $map->effective_start ?? $today,
                    'updated_at'      => now(),
                ]);
        });

        return response()->json(['success' => true, 'status' => 'active']);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolve the ordered list of jurisdiction scope IDs for a mass operation.
     *
     * map_view_*:          [$scopeId]
     * map_plus_children_*: [$scopeId, ...giantChildIds]
     * legislature_*:       All distinct non-null jurisdiction_ids in legislature_districts.
     *                      For _unassigned also adds $scopeId if not already present.
     */
    private function resolveMassScopeIds(
        string  $legislature_id,
        object  $leg,
        string  $scopeId,
        string  $operationScope,
        float   $rootQuota,
        ?string $mapId = null
    ): array {
        if (str_starts_with($operationScope, 'map_view_')) {
            return [$scopeId];
        }

        if (str_starts_with($operationScope, 'map_plus_children_')) {
            // Giant children = direct children with population / rootQuota >= 9.5
            $giantChildIds = DB::table('jurisdictions')
                ->where('parent_id', $scopeId)
                ->whereNull('deleted_at')
                ->whereNotNull('geom')
                ->get(['id', 'population'])
                ->filter(fn($j) => ((int) $j->population / max($rootQuota, 1)) >= 9.5)
                ->pluck('id')
                ->toArray();
            return array_merge([$scopeId], $giantChildIds);
        }

        if (str_starts_with($operationScope, 'giant_descendants_only_')) {
            // Only the giant direct children — excludes $scopeId itself.
            // Useful when the current scope is already seeded and you only want
            // to reseed/clear the sub-scopes of giant children.
            $giantChildIds = DB::table('jurisdictions')
                ->where('parent_id', $scopeId)
                ->whereNull('deleted_at')
                ->whereNotNull('geom')
                ->get(['id', 'population'])
                ->filter(fn($j) => ((int) $j->population / max($rootQuota, 1)) >= 9.5)
                ->pluck('id')
                ->toArray();
            return $giantChildIds;
        }

        // legislature_* — build the scope list from three sources so that a
        // fresh-start reseed (after full disband) works correctly:
        //
        //   1. Active district scopes   — scopes that already have live districts
        //                                 (handles incremental / partial reseeds)
        //   2. Apportioned sub-scopes   — jurisdictions with type_a_apportioned set
        //                                 by the ETL (giant countries, provinces, etc.)
        //                                 These must be re-seeded even when the table
        //                                 is empty, e.g. immediately after a full disband.
        //   3. $scopeId itself          — always include the current view scope so the
        //                                 root level is never silently skipped.
        //
        // The _unassigned vs _all distinction is enforced inside runAutoCompositeForScope
        // via $clearExisting, so both variants use the same full scope list here.

        $existingScopesQuery = DB::table('legislature_districts')
            ->where('legislature_id', $legislature_id)
            ->whereNull('deleted_at')
            ->whereNotNull('jurisdiction_id');
        if ($mapId !== null) {
            $existingScopesQuery->where('map_id', $mapId);
        }
        $existingScopes = $existingScopesQuery->distinct()->pluck('jurisdiction_id')->toArray();

        $apportionedScopes = DB::table('jurisdictions')
            ->whereNotNull('type_a_apportioned')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();

        return array_values(array_unique(
            array_merge([$scopeId], $existingScopes, $apportionedScopes)
        ));
    }

    /**
     * Core auto-composite algorithm for a single scope.
     *
     * Caller is responsible for the DB transaction boundary and recomputeColorIndices().
     * Returns ['districts_created' => int, 'error' => string|null].
     * 'error' is non-null for recoverable no-op cases (e.g. no compositable children).
     * Throws on genuine exceptions — caller should catch and roll back.
     *
     * @param int $seatBudget  Exact integer seat allocation for this scope
     *                         (leg->type_a_seats at root; type_a_apportioned at sub-scopes).
     */
    private function runAutoCompositeForScope(
        string  $legislature_id,
        object  $leg,
        string  $scopeId,
        bool    $clearExisting,
        int     $seatBudget,
        ?string $mapId = null
    ): array {
        // ── Step 1: Fetch ALL direct children with geometry ──────────────────
        $allChildrenRows = DB::select("
            SELECT
                j.id, j.name, j.population,
                ST_X(ST_Centroid(j.geom)) AS centroid_x,
                ST_Y(ST_Centroid(j.geom)) AS centroid_y
            FROM jurisdictions j
            WHERE j.parent_id = :scope_id
              AND j.deleted_at IS NULL
              AND j.geom IS NOT NULL
            ORDER BY j.population DESC
        ", ['scope_id' => $scopeId]);

        if (empty($allChildrenRows)) {
            return ['districts_created' => 0, 'error' => 'No children with geometry found at this scope'];
        }

        // ── Step 2: Level-local quota + fractional seats ──────────────────────
        $totalChildPop = array_sum(array_map(fn($c) => (int) $c->population, $allChildrenRows));
        $localQuota    = $totalChildPop / max($seatBudget, 1);

        foreach ($allChildrenRows as $c) {
            $c->fractional_seats = (float) $c->population / max($localQuota, 1);
        }

        // ── Step 3: Classify giants vs non-giants ─────────────────────────────
        $giantRows    = [];
        $nonGiantRows = [];
        foreach ($allChildrenRows as $c) {
            if ($c->fractional_seats >= 9.5) {
                $giantRows[] = $c;
            } else {
                $nonGiantRows[] = $c;
            }
        }

        // ── Step 4: Lock giant seat allocations — write type_a_apportioned ────
        $giantSeats = [];
        foreach ($giantRows as $g) {
            $seats = max(5, (int) round($g->fractional_seats));
            $giantSeats[$g->id] = $seats;
            DB::table('jurisdictions')->where('id', $g->id)->update(['type_a_apportioned' => $seats]);
        }

        // ── Step 5: Non-giant seat budget ─────────────────────────────────────
        $nonGiantBudget = $seatBudget - array_sum($giantSeats);

        if (empty($nonGiantRows)) {
            return ['districts_created' => 0, 'error' => 'No compositable (non-giant) children found at this scope'];
        }

        // ── Step 6: Filter already-assigned non-giants (when not clearing) ────
        if (!$clearExisting) {
            $nonGiantIds  = array_column($nonGiantRows, 'id');
            $assignedQuery = DB::table('legislature_district_jurisdictions as ldj')
                ->join('legislature_districts as ld', 'ld.id', '=', 'ldj.district_id')
                ->where('ld.legislature_id', $legislature_id)
                ->whereNull('ld.deleted_at')
                ->whereIn('ldj.jurisdiction_id', $nonGiantIds);
            if ($mapId !== null) {
                $assignedQuery->where('ld.map_id', $mapId);
            }
            $assignedIds  = $assignedQuery->pluck('ldj.jurisdiction_id')->toArray();
            $nonGiantRows = array_values(array_filter($nonGiantRows, fn($c) => !in_array($c->id, $assignedIds)));
        }

        if (empty($nonGiantRows)) {
            return ['districts_created' => 0, 'error' => 'No unassigned compositable children found at this scope'];
        }

        // Build childById + centroids for BFS (non-giants only)
        $childById = [];
        $centroids  = [];
        foreach ($nonGiantRows as $c) {
            $childById[$c->id] = $c;
            $centroids[$c->id] = ['x' => (float) $c->centroid_x, 'y' => (float) $c->centroid_y];
        }
        $childIds = array_column($nonGiantRows, 'id');

        // ── Step 7: Adjacency + BFS connected components ──────────────────────
        $idsStr = '{' . implode(',', $childIds) . '}';
        $edges  = DB::select("
            SELECT a.id AS j1, b.id AS j2
            FROM jurisdictions a
            JOIN jurisdictions b ON a.id < b.id
                AND ST_DWithin(
                    ST_SimplifyPreserveTopology(a.geom, 0.01),
                    ST_SimplifyPreserveTopology(b.geom, 0.01),
                    0.1
                )
            WHERE a.id = ANY(:ids1::uuid[])
              AND b.id = ANY(:ids2::uuid[])
              AND a.deleted_at IS NULL
              AND b.deleted_at IS NULL
        ", ['ids1' => $idsStr, 'ids2' => $idsStr]);

        $adj = [];
        foreach ($childIds as $id) $adj[$id] = [];
        foreach ($edges as $edge) {
            $adj[$edge->j1][] = $edge->j2;
            $adj[$edge->j2][] = $edge->j1;
        }

        $visited    = [];
        $components = [];
        foreach ($childIds as $id) {
            if (isset($visited[$id])) continue;
            $component = [];
            $queue     = [$id];
            $visited[$id] = true;
            while (!empty($queue)) {
                $curr        = array_shift($queue);
                $component[] = $curr;
                foreach ($adj[$curr] as $neighbor) {
                    if (!isset($visited[$neighbor])) {
                        $visited[$neighbor] = true;
                        $queue[] = $neighbor;
                    }
                }
            }
            $components[] = $component;
        }

        // ── Step 8: Geographic seed expansion per connected component ─────────
        $allBins = [];
        foreach ($components as $component) {
            $bins    = $this->geographicSeedExpansion($component, $childById, $adj, $centroids);
            $allBins = array_merge($allBins, $bins);
        }

        // Cross-component post-repair: merge undersized bins (< 5.0 fractional) into nearest
        // absorbable bin (merged total < 9.5). Handles isolated island jurisdictions.
        $globalBinFracs = array_map(fn($bin) =>
            array_sum(array_map(fn($jid) => (float) $childById[$jid]->fractional_seats, $bin)),
            $allBins
        );

        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($globalBinFracs as $i => $t) {
                if ($t >= 5.0 || empty($allBins[$i])) continue;
                $iCenter  = $this->binCentroid($allBins[$i], $centroids);
                $bestJ    = -1;
                $bestDist = PHP_FLOAT_MAX;
                foreach ($globalBinFracs as $j => $tj) {
                    if ($j === $i || empty($allBins[$j])) continue;
                    if ($tj + $t >= 9.5) continue;
                    $jCenter = $this->binCentroid($allBins[$j], $centroids);
                    $dx = $iCenter['x'] - $jCenter['x'];
                    $dy = $iCenter['y'] - $jCenter['y'];
                    $d  = $dx * $dx + $dy * $dy;
                    if ($d < $bestDist) { $bestDist = $d; $bestJ = $j; }
                }
                if ($bestJ >= 0) {
                    $allBins[$bestJ]        = array_merge($allBins[$bestJ], $allBins[$i]);
                    $globalBinFracs[$bestJ] += $globalBinFracs[$i];
                    $allBins[$i]            = [];
                    $globalBinFracs[$i]     = 0.0;
                    $changed = true;
                    break;
                }
            }
        }
        $allBins = array_values(array_filter($allBins, fn($b) => !empty($b)));

        // ── Step 9: Clear existing districts if requested ─────────────────────
        if ($clearExisting) {
            // Clear null-jurisdiction composites whose members are direct children of this scope
            $nullClearQuery = DB::table('legislature_districts AS ld')
                ->join('legislature_district_jurisdictions AS ldj', 'ldj.district_id', '=', 'ld.id')
                ->join('jurisdictions AS j', 'j.id', '=', 'ldj.jurisdiction_id')
                ->where('ld.legislature_id', $legislature_id)
                ->whereNull('ld.jurisdiction_id')
                ->where('j.parent_id', $scopeId)
                ->whereNull('j.deleted_at');
            if ($mapId !== null) {
                $nullClearQuery->where('ld.map_id', $mapId);
            }
            $nullIds = $nullClearQuery->distinct()->pluck('ld.id')->toArray();
            foreach ($nullIds as $eid) {
                DB::table('legislature_district_jurisdictions')->where('district_id', $eid)->delete();
                DB::table('legislature_districts')->where('id', $eid)->delete();
            }

            // Clear districts scoped directly to this jurisdiction
            $existClearQuery = DB::table('legislature_districts')
                ->where('legislature_id', $legislature_id)
                ->where('jurisdiction_id', $scopeId);
            if ($mapId !== null) {
                $existClearQuery->where('map_id', $mapId);
            }
            $existingIds = $existClearQuery->pluck('id');
            foreach ($existingIds as $eid) {
                DB::table('legislature_district_jurisdictions')->where('district_id', $eid)->delete();
                DB::table('legislature_districts')->where('id', $eid)->delete();
            }
        }

        // ── Step 10: Collect bin populations ─────────────────────────────────
        $binData = [];
        foreach ($allBins as $binJids) {
            if (empty($binJids)) continue;
            $pop = array_sum(array_map(fn($jid) => (int) $childById[$jid]->population, $binJids));
            $binData[] = [
                'jids'          => $binJids,
                'pop'           => $pop,
                'floor_override'=> false,   // set in Step 11
                'seats'         => 0,       // set in Step 11
                'fractional'    => 0.0,     // set in Step 11
            ];
        }

        // ── Step 11: Webster (Sainte-Laguë) distribution across bins ────────
        // effectiveBudget = nonGiantBudget (true remaining budget after locking giants).
        // Constitutional floor (≥5 per bin) applies only when the budget can support it.
        // When nonGiantBudget < 5×bins, distribute exactly what's available (floor_override=true).
        $totalBinPop     = array_sum(array_column($binData, 'pop'));
        $effectiveBudget = $nonGiantBudget;
        $binCount        = count($binData);
        $floorFeasible   = ($effectiveBudget >= $binCount * 5);
        $startSeats      = $floorFeasible ? 5 : 1;
        $binQuota        = $totalBinPop / max($effectiveBudget, 1);

        foreach ($binData as &$b) {
            $b['fractional']     = $b['pop'] / max($binQuota, 1);
            $b['floor_override'] = $b['fractional'] < 5.0;
            $b['seats']          = $startSeats;
        }
        unset($b);

        // Distribute remaining seats one-by-one using Webster priority (pop / (2s+1)).
        // When floor is not feasible, skip the floor_override gate so all budget is distributed.
        $remaining = $effectiveBudget - $startSeats * $binCount;
        for ($r = 0; $r < $remaining; $r++) {
            $bestIdx = -1; $bestPriority = -1.0;
            foreach ($binData as $i => $b) {
                if ($b['seats'] >= 9) continue;
                if ($floorFeasible && $b['floor_override']) continue;
                $priority = $b['pop'] / (2 * $b['seats'] + 1);
                if ($priority > $bestPriority) { $bestPriority = $priority; $bestIdx = $i; }
            }
            if ($bestIdx >= 0) $binData[$bestIdx]['seats']++;
        }

        // ── Step 12: Insert districts + update type_a_apportioned on members ──
        $districtsCreated = 0;
        foreach ($binData as $bin) {
            $distNumQ = DB::table('legislature_districts')
                ->where('legislature_id', $legislature_id)
                ->where('jurisdiction_id', $scopeId)
                ->whereNull('deleted_at');
            if ($mapId !== null) {
                $distNumQ->where('map_id', $mapId);
            }
            $districtNumber = 1 + (int) $distNumQ->max('district_number');

            $districtId = (string) \Illuminate\Support\Str::uuid();

            DB::table('legislature_districts')->insert([
                'id'               => $districtId,
                'legislature_id'   => $legislature_id,
                'map_id'           => $mapId,
                'jurisdiction_id'  => $scopeId,
                'district_number'  => $districtNumber,
                'seats'            => $bin['seats'],
                'fractional_seats' => $binQuota > 0 ? round($bin['pop'] / $binQuota, 6) : 0.0,
                'floor_override'   => $bin['floor_override'],
                'target_population'=> $bin['pop'],
                'actual_population'=> $bin['pop'],
                'status'           => 'active',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $memberships = array_map(fn($jid) => [
                'id'              => (string) \Illuminate\Support\Str::uuid(),
                'district_id'     => $districtId,
                'jurisdiction_id' => $jid,
            ], $bin['jids']);
            DB::table('legislature_district_jurisdictions')->insert($memberships);

            // Write type_a_apportioned on each member: their proportional share of district seats.
            // Enables accurate sub-scope seat budgets if these jurisdictions are ever drilled into.
            if ($bin['pop'] > 0) {
                foreach ($bin['jids'] as $jid) {
                    $memberPop   = (int) $childById[$jid]->population;
                    $memberShare = (int) round($memberPop * $bin['seats'] / $bin['pop']);
                    DB::table('jurisdictions')->where('id', $jid)->update(['type_a_apportioned' => max(1, $memberShare)]);
                }
            }

            // Compute and cache spatial stats (polsby_popper, num_geom_parts, is_contiguous)
            // so reseeded districts have stats immediately — same as manual create/update.
            $this->recomputeDistrict($districtId, $legislature_id, $leg);

            $districtsCreated++;
        }

        return ['districts_created' => $districtsCreated, 'error' => null];
    }

    /**
     * Recompute seats + geometry for a district based on its current members.
     * If the district has no remaining members, soft-delete it.
     */
    private function recomputeDistrict(string $districtId, string $legislatureId, object $leg): void
    {
        $jids = DB::table('legislature_district_jurisdictions as ldj')
            ->where('ldj.district_id', $districtId)
            ->pluck('ldj.jurisdiction_id')
            ->toArray();

        if (empty($jids)) {
            DB::table('legislature_districts')
                ->where('id', $districtId)
                ->update(['deleted_at' => now()]);
            return;
        }

        $totalPop = (int) DB::table('jurisdictions')->whereIn('id', $jids)->sum('population');

        // Use local quota from the district's scope rather than the root quota.
        $districtRow = DB::table('legislature_districts')->where('id', $districtId)->first();
        $distScopeId = $districtRow ? $districtRow->jurisdiction_id : null;
        if ($distScopeId) {
            $scopeChildrenPop = (int) DB::table('jurisdictions')
                ->where('parent_id', $distScopeId)
                ->whereNull('deleted_at')
                ->sum('population');
            $distScopeRow = DB::table('jurisdictions')->where('id', $distScopeId)->whereNull('deleted_at')->first();
            $reRootPop    = max((int) DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('population'), 1);
            if ($distScopeId === $leg->jurisdiction_id) {
                $distSeatBudget = (int) $leg->type_a_seats;
            } elseif ($distScopeRow && $distScopeRow->type_a_apportioned !== null) {
                $distSeatBudget = (int) $distScopeRow->type_a_apportioned;
            } else {
                $distSeatBudget = max(5, (int) round((int) ($distScopeRow ? $distScopeRow->population : 0) * (int) $leg->type_a_seats / $reRootPop));
            }
            $quota = $scopeChildrenPop / max($distSeatBudget, 1);
        } else {
            $reRootPop = max((int) DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('population'), 1);
            $quota = $reRootPop / max((int) $leg->type_a_seats, 1);
        }
        $fractional = $totalPop / max($quota, 1);
        $seats      = max(5, min(9, (int) round($fractional)));
        $floorOverride = $seats === 5 && $fractional < 5.0;

        // Pre-compute spatial stats from member jurisdiction geometries.
        // Running per-district at write time (create/update) is fast — typically
        // 1–40 member jurisdictions per district.  This avoids the O(n) ST_Union
        // fan-out that timed out when computed for all 274 Earth districts on page load.
        // Compactness: convex hull ratio + centroid spread.
        // Both metrics are better suited than Polsby-Popper for admin-unit districting
        // because they do not penalise natural coastlines or water bodies.
        //
        // convex_hull_ratio = ST_Area(union) / ST_Area(ST_ConvexHull(union))  [0–1, higher=better]
        // Union first so shared borders cancel cleanly before deriving metrics.
        $jidPlaceholders = implode(',', array_fill(0, count($jids), '?'));
        $spatialRow = DB::selectOne("
            WITH union_cte AS (
                SELECT ST_MakeValid(ST_Union(ST_MakeValid(j.geom))) AS geom
                FROM jurisdictions j
                WHERE j.id IN ({$jidPlaceholders})
                  AND j.geom IS NOT NULL AND j.deleted_at IS NULL
            )
            SELECT
                ST_Area(geom) / NULLIF(ST_Area(ST_ConvexHull(geom)), 0) AS convex_hull_ratio,
                ST_NumGeometries(geom)                                   AS num_geom_parts
            FROM union_cte
        ", $jids);

        // Contiguity: graph connectivity check via ST_Intersects adjacency + BFS.
        // Single-member districts are always contiguous by definition — their
        // internal island geography (Michigan UP, Hawaiian islands, etc.) is irrelevant.
        // Multi-member districts: two members are "adjacent" if their geometries
        // intersect (share a border).  BFS from the first member; if all N are
        // visited the district is contiguous.  FALSE means ≥1 member is isolated.
        if (count($jids) <= 1) {
            $isContiguous = true;
        } else {
            $jidPh1   = implode(',', array_fill(0, count($jids), '?'));
            $jidPh2   = implode(',', array_fill(0, count($jids), '?'));
            $adjPairs = DB::select("
                SELECT a.id AS a_id, b.id AS b_id
                FROM jurisdictions a
                JOIN jurisdictions b ON b.id > a.id
                    AND b.id IN ({$jidPh2})
                    AND b.geom IS NOT NULL AND b.deleted_at IS NULL
                    AND a.geom && ST_Expand(b.geom, 1.35)
                WHERE a.id IN ({$jidPh1})
                  AND a.geom IS NOT NULL AND a.deleted_at IS NULL
            ", array_merge($jids, $jids));

            $adj = [];
            foreach ($adjPairs as $p) {
                $adj[$p->a_id][] = $p->b_id;
                $adj[$p->b_id][] = $p->a_id;
            }
            $visited = [];
            $queue   = [$jids[0]];
            while (!empty($queue)) {
                $node = array_shift($queue);
                if (isset($visited[$node])) continue;
                $visited[$node] = true;
                foreach ($adj[$node] ?? [] as $nb) {
                    if (!isset($visited[$nb])) $queue[] = $nb;
                }
            }
            $isContiguous = count($visited) === count($jids);

            // If non-contiguous, check whether contiguity was even achievable.
            // Island jurisdictions (Hawaii, Puerto Rico, Guam…) can never be made
            // contiguous with mainland members — no map drawing can fix it.
            //
            // For each orphaned (BFS-unreachable) member, ask: does it share any
            // land border with ANY sibling jurisdiction (same parent_id)?
            // The GiST bbox pre-filter makes this near-instant for true islands —
            // Hawaii's bbox has zero overlap with any other US state → 0 candidates.
            // If ANY orphaned member has no sibling border at all, the non-contiguity
            // is geographic/unavoidable → override to contiguous (no flag).
            if (!$isContiguous) {
                $orphanedJids = array_values(array_filter($jids, fn($j) => !isset($visited[$j])));
                foreach ($orphanedJids as $oj) {
                    $hasSiblingBorder = DB::selectOne("
                        SELECT 1
                        FROM jurisdictions a
                        JOIN jurisdictions b
                            ON b.parent_id = a.parent_id
                            AND b.id != a.id
                            AND b.deleted_at IS NULL
                            AND b.geom IS NOT NULL
                            AND ST_Intersects(a.geom, b.geom)
                        WHERE a.id = ?
                          AND a.deleted_at IS NULL
                        LIMIT 1
                    ", [$oj]);
                    if (!$hasSiblingBorder) {
                        $isContiguous = true;
                        break;
                    }
                }
            }
        }

        // No geometry stored on the district record itself —
        // the revealed layer renders member jurisdiction polygons directly.
        DB::table('legislature_districts')
            ->where('id', $districtId)
            ->update([
                'seats'             => $seats,
                'fractional_seats'  => $fractional,
                'floor_override'    => $floorOverride,
                'actual_population' => $totalPop,
                'polsby_popper'     => null,
                'num_geom_parts'    => $spatialRow?->num_geom_parts !== null ? (int) $spatialRow->num_geom_parts : null,
                'convex_hull_ratio' => $spatialRow?->convex_hull_ratio !== null ? round((float) $spatialRow->convex_hull_ratio, 6) : null,
                'is_contiguous'     => $isContiguous,
                'updated_at'        => now(),
            ]);

        // Flush all revealed GeoJSON caches for this legislature.
        // The broad tag "revealed.{$legislatureId}" was added to every revealedGeoJson()
        // cache entry, so one flush here invalidates every scope × map × zoom combination.
        Cache::tags(["revealed.{$legislatureId}"])->flush();
    }

    /**
     * Greedy 4-color graph coloring for all districts at a given scope.
     * Sorts districts by adjacency degree (most connected first) for better results.
     * Adjacent districts (ST_Intersects) are guaranteed different color_index values.
     *
     * @param string      $legislatureId
     * @param string|null $scopeId           The scope jurisdiction ID.
     * @param string|null $rootJurisdictionId When provided, also includes ETL districts
     *                                        (jurisdiction_id IS NULL) if scopeId === root.
     */
    private function recomputeColorIndices(
        string  $legislatureId,
        ?string $scopeId,
        ?string $rootJurisdictionId = null,
        ?string $mapId = null
    ): void {
        if (!$scopeId) {
            // Null scopeId = ETL root districts; use the rootJurisdictionId as sentinel
            if (!$rootJurisdictionId) return;
        }

        $includeNull = ($rootJurisdictionId !== null && $scopeId === $rootJurisdictionId);

        $q = DB::table('legislature_districts')
            ->where('legislature_id', $legislatureId)
            ->where(function ($q) use ($scopeId, $includeNull) {
                $q->where('jurisdiction_id', $scopeId);
                if ($includeNull) {
                    $q->orWhereNull('jurisdiction_id');
                }
            })
            ->whereNull('deleted_at');

        if ($mapId !== null) {
            $q->where('map_id', $mapId);
        }

        $districtIds = $q->pluck('id')->toArray();

        if (empty($districtIds)) return;

        // Assign deterministic cycling colors (0→1→2→3→0…) sorted by UUID.
        // No geometry computation — ST_Intersects on large province polygons (Canada,
        // India, etc.) hangs regardless of simplification tolerance.  The ETL phase5
        // script is the authoritative source of proper 7-coloring; the PHP path just
        // needs to assign visually distinct non-monotone colors quickly.

        // Cross-scope collision avoidance: for non-root scopes (e.g. England inside UK),
        // find the color_indices already used by the PARENT scope's districts and start
        // the cycling offset from the first color not in that set.  This prevents the
        // common case where GBR 01 (UK scope, color 0) and GBR ENG 02 (England scope,
        // also color 0) both render as amber at UK map view, making them visually identical.
        $colorOffset = 0;
        if ($scopeId && $rootJurisdictionId && $scopeId !== $rootJurisdictionId) {
            $parentId = DB::table('jurisdictions')
                ->where('id', $scopeId)
                ->whereNull('deleted_at')
                ->value('parent_id');

            if ($parentId) {
                $pq = DB::table('legislature_districts')
                    ->where('legislature_id', $legislatureId)
                    ->where('jurisdiction_id', $parentId)
                    ->whereNull('deleted_at');
                if ($mapId !== null) {
                    $pq->where('map_id', $mapId);
                }
                $parentColors = $pq->pluck('color_index')->unique()->toArray();

                // Advance offset past any color already occupied in parent scope
                while ($colorOffset < 7 && in_array($colorOffset, $parentColors)) {
                    $colorOffset++;
                }
                if ($colorOffset >= 7) {
                    $colorOffset = 0;  // all 7 colors exhausted — wrap around
                }
            }
        }

        $sorted = array_values($districtIds);
        sort($sorted);
        $buckets = [[], [], [], [], [], [], []];
        foreach ($sorted as $i => $id) {
            $buckets[($i + $colorOffset) % 7][] = $id;
        }
        foreach ($buckets as $c => $ids) {
            if (!empty($ids)) {
                DB::table('legislature_districts')
                    ->whereIn('id', $ids)
                    ->update(['color_index' => $c]);
            }
        }
    }

    /**
     * Select k geographically spread seeds using a greedy farthest-first strategy
     * (k-means++ style initialization).
     *
     * Starts from the northernmost jurisdiction (max latitude), then repeatedly picks
     * the jurisdiction that is farthest from all already-chosen seeds. This guarantees
     * seeds are spread across the geographic extent of the component, so BFS expansion
     * from each seed produces roughly equal-area, compact regions.
     *
     * @param  array $jids      Jurisdiction IDs in this component
     * @param  array $centroids ['x' => lon, 'y' => lat] keyed by jurisdiction ID
     * @param  int   $k         Number of seeds to select
     * @return array            Array of k jurisdiction IDs
     */
    private function selectSeeds(array $jids, array $centroids, int $k): array
    {
        if ($k >= count($jids)) return $jids;

        // Start from the northernmost jurisdiction (highest latitude = top of map)
        $firstSeed = $jids[0];
        $maxLat    = PHP_FLOAT_MIN;
        foreach ($jids as $jid) {
            $lat = $centroids[$jid]['y'] ?? 0.0;
            if ($lat > $maxLat) {
                $maxLat    = $lat;
                $firstSeed = $jid;
            }
        }

        $seeds   = [$firstSeed];
        $seedSet = [$firstSeed => true];

        while (count($seeds) < $k) {
            $farthest   = null;
            $maxMinDist = -1.0;

            foreach ($jids as $jid) {
                if (isset($seedSet[$jid])) continue;

                // Minimum squared distance from this jurisdiction to any existing seed
                $minDist = PHP_FLOAT_MAX;
                foreach ($seeds as $seed) {
                    $dx = ($centroids[$jid]['x'] ?? 0.0) - ($centroids[$seed]['x'] ?? 0.0);
                    $dy = ($centroids[$jid]['y'] ?? 0.0) - ($centroids[$seed]['y'] ?? 0.0);
                    $d  = $dx * $dx + $dy * $dy; // squared — fine for argmax comparison
                    if ($d < $minDist) $minDist = $d;
                }

                if ($minDist > $maxMinDist) {
                    $maxMinDist = $minDist;
                    $farthest   = $jid;
                }
            }

            if ($farthest === null) break;
            $seeds[]           = $farthest;
            $seedSet[$farthest] = true;
        }

        return $seeds;
    }

    /**
     * Partition a connected component into geographically compact, contiguous districts
     * using seeded BFS expansion from k geographically spread starting jurisdictions.
     *
     * Algorithm:
     *  1. Determine k (number of districts) = ceil(totalFrac / 9.0)
     *  2. Select k seeds via selectSeeds() — northernmost first, then farthest-from-nearest
     *  3. Initialize k BFS queues, one per seed
     *  4. Round-robin: each turn, each bin BFS-grows by one adjacent unassigned jurisdiction
     *     (skipping bins already past 110% of target population unless they're the last active)
     *  5. Isolated jurisdictions (not reachable via adjacency) assigned to nearest bin by centroid
     *  6. Post-repair: merge undersized bins (< 5.0 fractional) into another if merged total < 9.5
     *
     * This replaces balancedLptPartition() which ignored geography entirely and produced
     * non-contiguous, geographically interleaved results.
     *
     * @param  array $jids      Jurisdiction IDs in this component
     * @param  array $childById jurisdiction data keyed by ID (population, fractional_seats, …)
     * @param  array $adj       Adjacency map [jid => [neighbor_jid, …]]
     * @param  array $centroids ['x' => lon, 'y' => lat] keyed by jurisdiction ID
     * @return array            Array of bins; each bin = array of jurisdiction IDs
     */
    private function geographicSeedExpansion(
        array $jids,
        array $childById,
        array $adj,
        array $centroids
    ): array {
        if (empty($jids)) return [];

        $totalFrac = array_sum(array_map(fn($jid) => (float) $childById[$jid]->fractional_seats, $jids));

        // Single district — no split needed
        if ($totalFrac < 9.5) return [$jids];

        $k         = max(2, (int) ceil($totalFrac / 9.0));
        $jidSet    = array_flip($jids); // O(1) membership test
        $totalPop  = array_sum(array_map(fn($jid) => (float) $childById[$jid]->population, $jids));
        $targetPop = $totalPop / $k;

        // --- Seed selection ---
        $seeds = $this->selectSeeds($jids, $centroids, $k);

        // --- Initialize bins ---
        $bins     = array_fill(0, $k, []);
        $binPops  = array_fill(0, $k, 0.0);
        $binFracs = array_fill(0, $k, 0.0); // track fractional total per bin — hard cap 9.5
        $assigned = [];
        $queues   = array_fill(0, $k, []); // BFS frontier per bin

        foreach ($seeds as $i => $seed) {
            $bins[$i][]      = $seed;
            $binPops[$i]     = (float) $childById[$seed]->population;
            $binFracs[$i]    = (float) $childById[$seed]->fractional_seats;
            $assigned[$seed] = $i;
            foreach ($adj[$seed] ?? [] as $n) {
                if (isset($jidSet[$n]) && !isset($assigned[$n])) {
                    $queues[$i][] = $n;
                }
            }
        }

        // --- BFS round-robin expansion ---
        // A bin is "full" when it exceeds the population target OR when it's already at/near
        // the constitutional 9.5 fractional cap (adding any more would breach the limit).
        $maxIter = count($jids) * $k * 3; // generous upper bound
        for ($iter = 0; $iter < $maxIter; $iter++) {
            $anyProgress = false;

            for ($i = 0; $i < $k; $i++) {
                // A bin is full if it has hit the population target OR the fractional cap
                $popFull  = $binPops[$i]  >= $targetPop  * 1.1;
                $fracFull = $binFracs[$i] >= 9.49; // leave a tiny buffer for float rounding
                $binFull  = $popFull || $fracFull;

                // How many bins still have room?
                $activeBins = 0;
                for ($j = 0; $j < $k; $j++) {
                    if ($binPops[$j] < $targetPop * 1.1 && $binFracs[$j] < 9.49) $activeBins++;
                }

                if ($binFull && $activeBins > 0) continue;

                // Drain stale (already-assigned) entries and find next live neighbor
                while (!empty($queues[$i])) {
                    $next     = array_shift($queues[$i]);
                    if (isset($assigned[$next]) || !isset($jidSet[$next])) continue;

                    $nextFrac = (float) $childById[$next]->fractional_seats;

                    // Constitutional hard limit: cannot exceed 9.5 fractional in one district.
                    // If this neighbor would push the bin over the cap, broadcast it to other
                    // bins' queues so it can be picked up geographically (don't discard it).
                    if ($binFracs[$i] + $nextFrac >= 9.5) {
                        for ($j = 0; $j < $k; $j++) {
                            if ($j !== $i && $binFracs[$j] + $nextFrac < 9.5) {
                                $queues[$j][] = $next;
                            }
                        }
                        continue; // skip for this bin, try next item in queue
                    }

                    // Assign to this bin
                    $bins[$i][]      = $next;
                    $binPops[$i]    += (float) $childById[$next]->population;
                    $binFracs[$i]   += $nextFrac;
                    $assigned[$next]  = $i;

                    // Enqueue unassigned neighbors
                    foreach ($adj[$next] ?? [] as $n) {
                        if (isset($jidSet[$n]) && !isset($assigned[$n])) {
                            $queues[$i][] = $n;
                        }
                    }
                    $anyProgress = true;
                    break; // one per bin per round — true round-robin
                }
            }

            if (!$anyProgress) break; // all queues drained — done or isolated jids remain
        }

        // --- Assign isolated jurisdictions (islands not reachable via adjacency) ---
        // Find nearest bin by centroid distance that won't exceed the 9.5 fractional cap.
        // If no existing bin can absorb it, create a new standalone bin.
        foreach ($jids as $jid) {
            if (isset($assigned[$jid])) continue;

            $jFrac      = (float) $childById[$jid]->fractional_seats;
            $nearestBin = -1;
            $minDist    = PHP_FLOAT_MAX;

            for ($i = 0; $i < $k; $i++) {
                if ($binFracs[$i] + $jFrac >= 9.5) continue; // would breach cap
                foreach ($bins[$i] as $binJid) {
                    $dx = ($centroids[$jid]['x'] ?? 0.0) - ($centroids[$binJid]['x'] ?? 0.0);
                    $dy = ($centroids[$jid]['y'] ?? 0.0) - ($centroids[$binJid]['y'] ?? 0.0);
                    $d  = $dx * $dx + $dy * $dy;
                    if ($d < $minDist) { $minDist = $d; $nearestBin = $i; }
                }
            }

            if ($nearestBin >= 0) {
                $bins[$nearestBin][] = $jid;
                $binFracs[$nearestBin] += $jFrac;
                $assigned[$jid]      = $nearestBin;
            } else {
                // No bin can absorb without exceeding 9.5 — standalone district
                $bins[]     = [$jid];
                $binFracs[] = $jFrac;
                $k++;
                $assigned[$jid] = $k - 1;
            }
        }

        // --- Post-repair: merge undersized bins (< 5.0 fractional) if possible ---
        // $binFracs is already live-tracked throughout BFS — no need to recompute.
        // Merges standalone tiny bins into adjacent ones (e.g. single-jurisdiction tiny islands)
        // as long as the merged total stays under 9.5. The cross-component post-repair in
        // autoComposite() handles the inter-component version of this same problem.
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($binFracs as $i => $t) {
                if ($t >= 5.0 || empty($bins[$i])) continue;
                $bestJ     = -1;
                $bestTotal = PHP_FLOAT_MAX;
                foreach ($binFracs as $j => $tj) {
                    if ($j === $i || empty($bins[$j])) continue;
                    if ($tj + $t < 9.5 && $tj < $bestTotal) {
                        $bestJ     = $j;
                        $bestTotal = $tj;
                    }
                }
                if ($bestJ >= 0) {
                    $bins[$bestJ]    = array_merge($bins[$bestJ], $bins[$i]);
                    $binFracs[$bestJ] += $binFracs[$i];
                    $bins[$i]        = [];
                    $binFracs[$i]    = 0.0;
                    $changed         = true;
                    break;
                }
            }
        }

        return array_values(array_filter($bins, fn($b) => !empty($b)));
    }

    /**
     * Compute the average centroid of a set of jurisdictions.
     * Used for cross-component post-repair merging (e.g. joining island bins to mainland bins).
     */
    private function binCentroid(array $jids, array $centroids): array
    {
        $x = 0.0;
        $y = 0.0;
        $n = count($jids);
        foreach ($jids as $jid) {
            $x += $centroids[$jid]['x'] ?? 0.0;
            $y += $centroids[$jid]['y'] ?? 0.0;
        }
        return [
            'x' => $n > 0 ? $x / $n : 0.0,
            'y' => $n > 0 ? $y / $n : 0.0,
        ];
    }

    /**
     * Return districts and expandable children at a given scope — used by the
     * sidebar inline-expansion tree (no Inertia, plain JSON).
     *
     * GET /api/legislatures/{id}/districts-at?scope={jurisdiction_id}
     */
    public function districtsAt(Request $request, string $legislature_id): JsonResponse
    {
        $scopeId = $request->query('scope');
        if (!$scopeId) {
            return response()->json(['error' => 'scope parameter required'], 422);
        }

        $leg = DB::table('legislatures')->where('id', $legislature_id)->first();
        if (!$leg) {
            return response()->json(['error' => 'Legislature not found'], 404);
        }

        $rootPop    = max((int) DB::table('jurisdictions')
            ->where('id', $leg->jurisdiction_id)->value('population'), 1);
        $totalSeats = (int) $leg->type_a_seats;
        $rootQuota  = $rootPop / $totalSeats;

        $scope = DB::table('jurisdictions')->where('id', $scopeId)->first();
        if (!$scope) {
            return response()->json(['error' => 'Scope not found'], 404);
        }
        // Replicate show()'s ancestor-chain logic so "USA CAL 01" appears consistently
        // in the lazy-loaded sidebar at all scope levels, not just "CAL 01".
        $atAncestors = DB::select("
            WITH RECURSIVE anc AS (
                SELECT id, name, parent_id, iso_code, adm_level FROM jurisdictions WHERE id = :anc_start
                UNION ALL
                SELECT j.id, j.name, j.parent_id, j.iso_code, j.adm_level FROM jurisdictions j
                JOIN anc ON j.id = anc.parent_id WHERE j.deleted_at IS NULL
            )
            SELECT id, name, iso_code, adm_level FROM anc
        ", ['anc_start' => $scopeId]);
        $atAncestors = array_reverse($atAncestors);

        $atIsRoot = ($scopeId === $leg->jurisdiction_id);
        $scopeShortCode = null;
        if (!$atIsRoot) {
            $countryJur  = count($atAncestors) >= 2 ? $atAncestors[1] : $scope;
            $countryCode = $this->makeShortCode($countryJur->name, $countryJur->iso_code ?? null, (int) $countryJur->adm_level);
            if (count($atAncestors) >= 3) {
                $ownCode        = $this->makeShortCode($scope->name, $scope->iso_code ?? null, (int) $scope->adm_level);
                $scopeShortCode = $countryCode . ' ' . $ownCode;
            } else {
                $scopeShortCode = $countryCode;
            }
        }

        $atMapId = $this->getMapId($legislature_id, $request->query('map'));

        // Fetch all member rows for districts belonging to this scope.
        // Phase 4 sub-districts do NOT store jurisdiction_id = scopeId; instead they
        // are identified by their member jurisdictions' parentage:
        //   Branch 1 (ADM2 members): j.parent_id = scopeId
        //   Branch 2 (ADM3 members): j.parent_id.parent_id = scopeId
        $dmRows = DB::table('legislature_districts AS ld')
            ->join('legislature_district_jurisdictions AS ldj', 'ldj.district_id', '=', 'ld.id')
            ->join('jurisdictions AS j', function ($join) {
                $join->on('j.id', '=', 'ldj.jurisdiction_id')
                     ->whereNull('j.deleted_at');
            })
            ->where('ld.legislature_id', $legislature_id)
            ->whereNull('ld.deleted_at')
            ->when($atMapId, fn($q) => $q->where('ld.map_id', $atMapId))
            ->where(function ($q) use ($scopeId) {
                // ADM2: member is a direct child of scope
                $q->where('j.parent_id', $scopeId)
                  // ADM3: member's parent is a direct child of scope
                  ->orWhereExists(function ($sub) use ($scopeId) {
                      $sub->from('jurisdictions AS parent')
                          ->whereColumn('parent.id', 'j.parent_id')
                          ->where('parent.parent_id', $scopeId)
                          ->whereNull('parent.deleted_at');
                  });
            })
            ->select(
                'ld.id', 'ld.seats', 'ld.district_number', 'ld.actual_population',
                'ld.fractional_seats', 'ld.color_index', 'ld.floor_override',
                'j.id AS jid', 'j.name AS jname', 'j.iso_code AS jiso', 'j.adm_level AS jadm',
                'j.population AS jpop',
                DB::raw('(SELECT COUNT(*) FROM jurisdictions c WHERE c.parent_id = j.id AND c.deleted_at IS NULL) AS jchild_count')
            )
            ->get();

        $dmap = [];
        foreach ($dmRows as $row) {
            $did = $row->id;
            if (!isset($dmap[$did])) {
                $dmap[$did] = [
                    'id'               => $did,
                    'seats'            => (int) $row->seats,
                    'district_number'  => (int) $row->district_number,
                    'population'       => (int) $row->actual_population,
                    'fractional_seats' => (float) $row->fractional_seats,
                    'color_index'      => (int) $row->color_index,
                    'floor_override'   => (bool) $row->floor_override,
                    '_member_codes'    => [],
                    'members'          => [],
                ];
            }
            $dmap[$did]['_member_codes'][] = $this->makeShortCode($row->jname, $row->jiso, (int) $row->jadm);
            $dmap[$did]['members'][] = [
                'id'          => $row->jid,
                'name'        => $row->jname,
                'population'  => (int) $row->jpop,
                'child_count' => (int) $row->jchild_count,
            ];
        }

        $districts = [];
        foreach ($dmap as &$d) {
            $mc = count($d['_member_codes']);
            if ($mc === 1) {
                $d['name'] = reset($d['_member_codes']);
            } else {
                $num = str_pad($d['district_number'], 2, '0', STR_PAD_LEFT);
                $d['name'] = $scopeShortCode . ' ' . $num;
            }
            unset($d['_member_codes']);

            // Phase 4 sub-districts often have null actual_population stored in the DB
            // (the ETL doesn't back-fill them). Derive from member populations so the
            // sidebar can display meaningful numbers instead of 0.
            if ($d['population'] === 0 && count($d['members']) > 0) {
                $d['population'] = (int) array_sum(array_column($d['members'], 'population'));
            }

            $districts[] = $d;
        }
        unset($d);

        // Always recompute fractional_seats from the actual Webster seat allocation
        // (SUM of ld.seats).  This is independent of how fractional_seats was stored
        // (root-quota vs local-quota) and guarantees composite fracs sum to exactly
        // the district seat total shown in the sidebar.
        if (count($districts) > 0) {
            $compositeSeats = array_sum(array_column($districts, 'seats'));
            if ($compositeSeats > 0) {
                $totalDistPop = array_sum(array_column($districts, 'population'));
                if ($totalDistPop > 0) {
                    $dLocalQ = $totalDistPop / $compositeSeats;
                    foreach ($districts as &$d) {
                        $d['fractional_seats'] = round($d['population'] / $dLocalQ, 2);
                    }
                    unset($d);
                }
            }
        }

        // Sort by seats descending
        usort($districts, fn($a, $b) => $b['seats'] - $a['seats']);

        // Giant children of this scope (fractional_seats >= 9.5).
        // has_districts uses member-based lookup (same ADM2/ADM3 logic as $dmRows above)
        // so it correctly detects Phase 4 sub-districts rather than relying on jurisdiction_id FK.
        $childRows = DB::select("
            SELECT
                j.id, j.name, j.iso_code, j.adm_level, j.population,
                (CAST(j.population AS numeric) * :total_seats / :root_pop) AS fractional_seats,
                (SELECT COUNT(*) FROM jurisdictions c
                 WHERE c.parent_id = j.id AND c.deleted_at IS NULL) AS child_count,
                EXISTS (
                    SELECT 1
                    FROM legislature_districts ld2
                    JOIN legislature_district_jurisdictions ldj2 ON ldj2.district_id = ld2.id
                    JOIN jurisdictions jm ON jm.id = ldj2.jurisdiction_id AND jm.deleted_at IS NULL
                    WHERE ld2.legislature_id = :leg_id
                      AND ld2.deleted_at IS NULL
                      AND (
                          jm.parent_id = j.id
                          OR EXISTS (
                              SELECT 1 FROM jurisdictions jp
                              WHERE jp.id = jm.parent_id
                                AND jp.parent_id = j.id
                                AND jp.deleted_at IS NULL
                          )
                      )
                ) AS has_districts
            FROM jurisdictions j
            WHERE j.parent_id = :scope_id
              AND j.deleted_at IS NULL
              AND (CAST(j.population AS numeric) * :total_seats2 / :root_pop2) >= 9.5
        ", [
            'total_seats'  => $totalSeats,
            'root_pop'     => $rootPop,
            'leg_id'       => $legislature_id,
            'scope_id'     => $scopeId,
            'total_seats2' => $totalSeats,
            'root_pop2'    => $rootPop,
        ]);

        $giants = [];
        foreach ($childRows as $c) {
            $giants[] = [
                'id'               => $c->id,
                'name'             => $c->name,
                'population'       => (int) $c->population,
                'fractional_seats' => round((float) $c->fractional_seats, 2),
                'child_count'      => (int) $c->child_count,
                'has_districts'    => (bool) $c->has_districts,
            ];
        }

        return response()->json(compact('districts', 'giants'));
    }

    /**
     * Compute constitutional validation flags for the legislature browser.
     *
     * Checks performed:
     *   1. Cap — total district seats exceed legislature.type_a_seats (root scope only)
     *   2. Overage — a giant's child districts sum to more seats than round(giant.fractional)
     *   3. Unevenness — a giant's child districts are more uneven than the constitutional ideal
     *   4. Floor exceptions — districts with floor_override (informational)
     *
     * @param  string  $legId
     * @param  object  $leg         legislatures row (stdClass)
     * @param  string  $scopeId
     * @param  array   $children    raw stdClass rows from show()'s DB::select on jurisdictions
     * @param  array   $districts   processed district array (from $districtMap)
     */
    private function computeValidationFlags(
        string  $legId,
        object  $leg,
        string  $scopeId,
        array   $children,
        array   $districts,
        ?string $mapId = null
    ): array {
        $flags = [
            'cap'               => null,
            'floor_exceptions'  => [],
            'deep_overages'     => [],   // over OR under budget (delta = actual − budget)
            'deep_unevenness'   => [],
            'incomplete_scopes' => [],   // scopes with unassigned compositable children
        ];

        // ── Flag 1: Legislature seat cap (root scope only) — over budget only ─
        // Under-budget at root is expected during districting (not all scopes drilled yet).
        // The incomplete_scopes flag handles the "not fully assigned" signal separately.
        if ($scopeId === $leg->jurisdiction_id) {
            $capQuery = DB::table('legislature_districts')
                ->where('legislature_id', $legId)
                ->whereNull('deleted_at');
            if ($mapId !== null) {
                $capQuery->where('map_id', $mapId);
            }
            $total = (int) $capQuery->sum('seats');
            if ($total !== (int) $leg->type_a_seats) {
                $flags['cap'] = [
                    'total' => $total,
                    'max'   => (int) $leg->type_a_seats,
                    'delta' => $total - (int) $leg->type_a_seats,  // positive = over, negative = under
                ];
            }
        }

        // ── Flag 4: Floor exceptions (informational) ──────────────────────────
        foreach ($districts as $d) {
            if ($d['floor_override']) {
                $flags['floor_exceptions'][] = [
                    'district_id'   => $d['id'],
                    'district_name' => $d['name'],
                    'seats'         => $d['seats'],
                    'fractional'    => $d['fractional_seats'],
                ];
            }
        }

        // ── Flag 5: Scoped deep scan — current scope and all descendants ─────────
        // Shows overage/unevenness for every jurisdiction-scope that has districts and
        // a known seat budget (type_a_apportioned), provided it is the current scope or
        // a descendant of it at any depth.  This is a fixed-depth ancestor-walk (up to
        // p3 = great-grandparent) rather than a recursive CTE: the district table is
        // small and LEFT JOIN on indexed parent_id is fast.  Depth-4 covers the maximum
        // real hierarchy: Earth → Country → State → County.
        // Examples:
        //   Earth scope   → sees Earth + all countries + all states + all counties with districts
        //   USA scope     → sees USA + all US states + US county-level scopes
        //   California    → sees California + California county-level scopes
        $deepMapClause  = $mapId !== null ? 'AND ld.map_id = ?' : '';
        $deepMapBinding = $mapId !== null ? [$mapId] : [];

        $deepScopeRows = DB::select("
            SELECT
                j.id         AS scope_id,
                j.name       AS scope_name,
                j.type_a_apportioned AS budget,
                COUNT(ld.id) AS num_districts,
                SUM(ld.seats)::int AS seat_sum,
                MAX(ld.seats)::int AS max_seats,
                MIN(ld.seats)::int AS min_seats,
                BOOL_OR(ld.floor_override) AS has_floor
            FROM legislature_districts ld
            JOIN jurisdictions j  ON j.id  = ld.jurisdiction_id AND j.deleted_at  IS NULL
            LEFT JOIN jurisdictions p1 ON p1.id = j.parent_id  AND p1.deleted_at IS NULL
            LEFT JOIN jurisdictions p2 ON p2.id = p1.parent_id AND p2.deleted_at IS NULL
            LEFT JOIN jurisdictions p3 ON p3.id = p2.parent_id AND p3.deleted_at IS NULL
            WHERE ld.legislature_id = ?
              AND ld.deleted_at IS NULL
              AND j.type_a_apportioned IS NOT NULL
              AND (j.id = ? OR p1.id = ? OR p2.id = ? OR p3.id = ?)
              {$deepMapClause}
            GROUP BY j.id, j.name, j.type_a_apportioned
        ", array_merge([$legId, $scopeId, $scopeId, $scopeId, $scopeId], $deepMapBinding));

        foreach ($deepScopeRows as $row) {
            $budget   = (int) $row->budget;
            $seatSum  = (int) $row->seat_sum;
            $numDist  = (int) $row->num_districts;
            $maxS     = (int) $row->max_seats;
            $minS     = (int) $row->min_seats;
            $hasFloor = (bool) $row->has_floor;

            if ($seatSum > $budget) {
                // Over-budget only — under-budget is normal when giants handle sub-scopes.
                $flags['deep_overages'][] = [
                    'scope_id'   => $row->scope_id,
                    'scope_name' => $row->scope_name,
                    'budget'     => $budget,
                    'actual'     => $seatSum,
                    'delta'      => $seatSum - $budget,
                ];
            } elseif ($numDist >= 2 && !$hasFloor && ($maxS - $minS) > 1) {
                $idLo = (int) floor($seatSum / $numDist);
                $idHi = (int) ceil($seatSum / $numDist);
                if ($idLo >= 5 && $idHi <= 9) {
                    $flags['deep_unevenness'][] = [
                        'scope_id'    => $row->scope_id,
                        'scope_name'  => $row->scope_name,
                        'ideal_range' => [$idLo, $idHi],
                        'max_seats'   => $maxS,
                        'min_seats'   => $minS,
                    ];
                }
            }
        }

        // ── Flag 6: Incomplete scopes — unassigned compositable children ─────
        // A scope is flagged only when:
        //   (a) it is the root scope, OR it has ≥1 district on THIS legislature+map
        //       (map-aware: avoids false positives from type_a_apportioned set by other maps)
        //   (b) it is NOT itself a district member (so non-giant composites like Puerto Rico
        //       are excluded — their sub-geography doesn't need separate assignment)
        //   (c) it has direct children with geometry not yet in any district on this map,
        //       where those children are NOT themselves giant drill-down scopes (identified
        //       by having their own districts on this map or being a district member).
        $incompMapClause  = $mapId !== null ? 'AND ld.map_id = ?'   : '';
        $incompMapClause2 = $mapId !== null ? 'AND ld2.map_id = ?'  : '';
        $incompMapClause3 = $mapId !== null ? 'AND ld3.map_id = ?'  : '';
        $incompMapClause4 = $mapId !== null ? 'AND ld4.map_id = ?'  : '';
        $incompMapBinding = $mapId !== null ? [$mapId] : [];

        $incompleteRows = DB::select("
            SELECT
                j.id         AS scope_id,
                j.name       AS scope_name,
                COUNT(child.id)::int AS unassigned_count
            FROM jurisdictions j
            LEFT JOIN jurisdictions p1 ON p1.id = j.parent_id  AND p1.deleted_at IS NULL
            LEFT JOIN jurisdictions p2 ON p2.id = p1.parent_id AND p2.deleted_at IS NULL
            LEFT JOIN jurisdictions p3 ON p3.id = p2.parent_id AND p3.deleted_at IS NULL
            -- (a) scope has ≥1 district on this legislature+map
            LEFT JOIN (
                SELECT DISTINCT jurisdiction_id
                FROM legislature_districts ld3
                WHERE ld3.legislature_id = ?
                  AND ld3.deleted_at IS NULL
                  {$incompMapClause3}
            ) scope_has_districts ON scope_has_districts.jurisdiction_id = j.id
            -- (b) scope is NOT itself a district member on this legislature+map
            LEFT JOIN (
                SELECT ldj2.jurisdiction_id
                FROM legislature_district_jurisdictions ldj2
                JOIN legislature_districts ld2 ON ld2.id = ldj2.district_id
                    AND ld2.legislature_id = ?
                    AND ld2.deleted_at IS NULL
                    {$incompMapClause2}
            ) self_assigned ON self_assigned.jurisdiction_id = j.id
            -- children to check
            JOIN jurisdictions child ON child.parent_id = j.id
                AND child.deleted_at IS NULL
                AND child.geom IS NOT NULL
            -- (c) child is not in any district on this map
            LEFT JOIN (
                SELECT ldj.jurisdiction_id
                FROM legislature_district_jurisdictions ldj
                JOIN legislature_districts ld ON ld.id = ldj.district_id
                    AND ld.legislature_id = ?
                    AND ld.deleted_at IS NULL
                    {$incompMapClause}
            ) assigned ON assigned.jurisdiction_id = child.id
            -- (c) child is not itself a giant drill-down scope (has its own districts on this map)
            LEFT JOIN (
                SELECT DISTINCT jurisdiction_id
                FROM legislature_districts ld4
                WHERE ld4.legislature_id = ?
                  AND ld4.deleted_at IS NULL
                  {$incompMapClause4}
            ) child_has_districts ON child_has_districts.jurisdiction_id = child.id
            WHERE j.deleted_at IS NULL
              AND (j.id = ? OR scope_has_districts.jurisdiction_id IS NOT NULL)
              AND self_assigned.jurisdiction_id IS NULL
              AND (j.id = ? OR p1.id = ? OR p2.id = ? OR p3.id = ?)
              AND assigned.jurisdiction_id IS NULL
              AND child_has_districts.jurisdiction_id IS NULL
            GROUP BY j.id, j.name
            HAVING COUNT(child.id) > 0
        ", array_merge(
            [$legId], $incompMapBinding,   // scope_has_districts subquery
            [$legId], $incompMapBinding,   // self_assigned subquery
            [$legId], $incompMapBinding,   // assigned subquery
            [$legId], $incompMapBinding,   // child_has_districts subquery
            [$leg->jurisdiction_id,        // root scope OR check
             $scopeId, $scopeId, $scopeId, $scopeId]  // depth filter
        ));

        foreach ($incompleteRows as $row) {
            $flags['incomplete_scopes'][] = [
                'scope_id'         => $row->scope_id,
                'scope_name'       => $row->scope_name,
                'unassigned_count' => (int) $row->unassigned_count,
            ];
        }

        return $flags;
    }

    /**
     * Compute informational quality statistics for the current scope's districts.
     *
     * Returns four metric groups:
     *   - population_equality : max deviation %, avg deviation %, range ratio
     *   - compactness         : mean / min / max Polsby-Popper scores (from cached column; null if not yet backfilled)
     *   - contiguity          : count of non-contiguous districts (from cached column; null if not yet backfilled)
     *   - community_integrity : % of population in districts using natural administrative borders
     */
    private function computeConstitutionalStats(
        string  $legislatureId,
        string  $scopeId,
        array   $districts,
        float   $quota,
        ?string $mapId = null
    ): array {
        $stats = [
            'population_equality' => null,
            'shape_compactness'   => null,
            'contiguity'          => null,
            'community_integrity' => null,
        ];
        if (empty($districts) || $quota <= 0) return $stats;

        $mapClauseLeaf  = $mapId !== null ? 'AND ld.map_id = ?' : '';
        $mapBindingLeaf = $mapId !== null ? [$mapId] : [];

        // ── Leaf districts in scope subtree ───────────────────────────────────
        // Gather ALL districts whose scope (ld.jurisdiction_id) is the current
        // scope or any descendant up to 4 levels deep.  This means at USA scope
        // we include both USA-level districts AND California sub-districts, etc.
        $leafRows = DB::select("
            SELECT
                ld.id,
                ld.seats,
                ld.district_number,
                ld.convex_hull_ratio,
                ld.is_contiguous,
                ld.jurisdiction_id AS scope_id,
                scope_j.name       AS scope_name,
                scope_j.iso_code   AS scope_iso,
                scope_j.adm_level  AS scope_adm,
                scope_j.population AS scope_pop,
                (SELECT COUNT(*) FROM jurisdictions child_j
                 WHERE child_j.parent_id = scope_j.id
                   AND child_j.deleted_at IS NULL) AS scope_child_count,
                (SELECT COUNT(*) FROM legislature_district_jurisdictions ldj_mc
                 WHERE ldj_mc.district_id = ld.id) AS member_count,
                COALESCE(ld.actual_population, (
                    SELECT SUM(jm.population)
                    FROM legislature_district_jurisdictions ldj_inner
                    JOIN jurisdictions jm ON jm.id = ldj_inner.jurisdiction_id AND jm.deleted_at IS NULL
                    WHERE ldj_inner.district_id = ld.id
                )) AS pop
            FROM legislature_districts ld
            JOIN jurisdictions scope_j ON scope_j.id = ld.jurisdiction_id AND scope_j.deleted_at IS NULL
            LEFT JOIN jurisdictions p1 ON p1.id = scope_j.parent_id AND p1.deleted_at IS NULL
            LEFT JOIN jurisdictions p2 ON p2.id = p1.parent_id        AND p2.deleted_at IS NULL
            LEFT JOIN jurisdictions p3 ON p3.id = p2.parent_id        AND p3.deleted_at IS NULL
            WHERE ld.legislature_id = ?
              AND ld.deleted_at IS NULL
              {$mapClauseLeaf}
              AND (scope_j.id = ? OR p1.id = ? OR p2.id = ? OR p3.id = ?)
        ", array_merge(
            [$legislatureId], $mapBindingLeaf,
            [$scopeId, $scopeId, $scopeId, $scopeId]
        ));

        if (empty($leafRows)) return $stats;

        // ── Population Equality ───────────────────────────────────────────────
        // Build enriched per-district data with scope info for extremes + tiers.
        $districtData = [];
        foreach ($leafRows as $r) {
            $pop   = (int) $r->pop;
            $seats = (int) $r->seats;
            if ($seats <= 0 || $pop <= 0) continue;

            $popPerSeat  = $pop / $seats;
            $deviationPct = abs($popPerSeat - $quota) / $quota * 100;
            $code = $this->makeShortCode($r->scope_name, $r->scope_iso, (int) $r->scope_adm);
            $label = $code . ' ' . str_pad((int) $r->district_number, 2, '0', STR_PAD_LEFT);

            $districtData[] = [
                'scope_id'          => $r->scope_id,
                'scope_name'        => $r->scope_name,
                'district_label'    => $label,
                'pop'               => $pop,
                'pop_per_seat'      => $popPerSeat,
                'deviation_pct'     => round($deviationPct, 2),
                'scope_pop'         => (int) $r->scope_pop,
                'scope_child_count' => (int) $r->scope_child_count,
                'member_count'      => (int) $r->member_count,
            ];
        }

        if (!empty($districtData)) {
            // Extremes: lowest pop_per_seat = over-represented, highest = under-represented
            $mostOver  = null;
            $mostUnder = null;
            foreach ($districtData as $d) {
                if ($mostOver === null  || $d['pop_per_seat'] < $mostOver['pop_per_seat'])  $mostOver  = $d;
                if ($mostUnder === null || $d['pop_per_seat'] > $mostUnder['pop_per_seat']) $mostUnder = $d;
            }

            // Tier breakdown: Good ≤5%, OK 5–10%, Bad >10%
            $tiers = [
                'good' => ['count' => 0, 'population' => 0],
                'ok'   => ['count' => 0, 'population' => 0],
                'bad'  => ['count' => 0, 'population' => 0],
            ];
            $deviations = [];
            $popPerSeats = [];
            foreach ($districtData as $d) {
                $deviations[]  = $d['deviation_pct'] / 100;
                $popPerSeats[] = $d['pop_per_seat'];

                if ($d['deviation_pct'] <= 5.0) {
                    $tiers['good']['count']++;
                    $tiers['good']['population'] += $d['pop'];
                } elseif ($d['deviation_pct'] <= 10.0) {
                    $tiers['ok']['count']++;
                    $tiers['ok']['population'] += $d['pop'];
                } else {
                    $tiers['bad']['count']++;
                    $tiers['bad']['population'] += $d['pop'];
                }
            }

            $total = count($districtData);
            foreach ($tiers as &$t) {
                $t['pct'] = round($t['count'] / $total * 100, 1);
            }
            unset($t);

            $stats['population_equality'] = [
                'district_count'    => $total,
                'max_deviation_pct' => round(max(array_column($districtData, 'deviation_pct')), 2),
                'avg_deviation_pct' => round(array_sum($deviations) / count($deviations) * 100, 2),
                'range_ratio'       => round(max($popPerSeats) / max(min($popPerSeats), 1), 3),
                'most_over' => [
                    'scope_id'       => $mostOver['scope_id'],
                    'scope_name'     => $mostOver['scope_name'],
                    'district_label' => $mostOver['district_label'],
                    'deviation_pct'  => $mostOver['deviation_pct'],
                ],
                'most_under' => [
                    'scope_id'       => $mostUnder['scope_id'],
                    'scope_name'     => $mostUnder['scope_name'],
                    'district_label' => $mostUnder['district_label'],
                    'deviation_pct'  => $mostUnder['deviation_pct'],
                ],
                'tiers'            => $tiers,
                'total_population' => array_sum(array_column($districtData, 'pop')),
            ];
        }

        // ── Community Integrity ───────────────────────────────────────────────
        // A district loses community integrity ONLY if its scope jurisdiction is
        // a "leaf giant": fractional_seats >= 9.5 AND has no child jurisdictions.
        // These are the only cases that would require artificial line-drawing tools.
        // Giants WITH children can always be sub-districted along natural borders.
        $integrityCount = 0;
        $integrityPop   = 0;
        $totalPop       = 0;
        foreach ($districtData as $d) {
            $scopeFrac   = $quota > 0 ? $d['scope_pop'] / $quota : 0;
            $isLeafGiant = ($scopeFrac >= 9.5) && ($d['scope_child_count'] === 0);
            $totalPop   += $d['pop'];
            if (!$isLeafGiant) {
                $integrityCount++;
                $integrityPop += $d['pop'];
            }
        }
        $ciTotal = count($districtData);
        $stats['community_integrity'] = [
            'pct'              => $totalPop > 0 ? round($integrityPop / $totalPop * 100, 1) : 100.0,
            'good_count'       => $integrityCount,
            'total_count'      => $ciTotal,
            'good_population'  => $integrityPop,
            'total_population' => $totalPop,
        ];

        // ── Spatial stats: read pre-computed cached columns ──────────────────
        // polsby_popper and num_geom_parts are written at every district write path:
        //   • recomputeDistrict() — PHP create/update
        //   • ETL Phase 2 + Phase 4 — after storing ld.geom
        // Page-load is now O(n) column reads — zero ST_Union, zero geometry computation.
        $leafIds = array_column($leafRows, 'id');
        if (!empty($leafIds)) {
            // Build member_count lookup keyed by district id (already in leafRows)
            $memberCountById = [];
            foreach ($leafRows as $r) {
                $memberCountById[$r->id] = (int) $r->member_count;
            }

            // ── Shape Compactness (Convex Hull Ratio) ────────────────────────
            // CHR = ST_Area(union) / ST_Area(ST_ConvexHull(union))
            // 1.0 = perfectly convex; lower = more concave/irregular.
            // Tiers: Good ≥ 0.70 | OK 0.50–0.70 | Irregular < 0.50
            $chrTiers = [
                'good' => ['count' => 0, 'population' => 0],
                'ok'   => ['count' => 0, 'population' => 0],
                'bad'  => ['count' => 0, 'population' => 0],
            ];
            $chrScored = 0;
            $chrSum    = 0.0;
            $chrTotalPop = 0;
            foreach ($leafRows as $r) {
                if ($r->convex_hull_ratio === null) continue;
                $chr = (float) $r->convex_hull_ratio;
                $pop = (int) ($r->pop ?? 0);
                $chrScored++;
                $chrSum    += $chr;
                $chrTotalPop += $pop;
                if ($chr >= 0.70) {
                    $chrTiers['good']['count']++;
                    $chrTiers['good']['population'] += $pop;
                } elseif ($chr >= 0.50) {
                    $chrTiers['ok']['count']++;
                    $chrTiers['ok']['population'] += $pop;
                } else {
                    $chrTiers['bad']['count']++;
                    $chrTiers['bad']['population'] += $pop;
                }
            }
            if ($chrScored > 0) {
                foreach ($chrTiers as &$t) {
                    $t['pct'] = round($t['count'] / $chrScored * 100, 1);
                }
                unset($t);
                $stats['shape_compactness'] = [
                    'mean'            => round($chrSum / $chrScored, 3),
                    'scored'          => $chrScored,
                    'total'           => count($leafIds),
                    'total_population'=> $chrTotalPop,
                    'tiers'           => $chrTiers,
                ];
            }

            // Contiguity — is_contiguous from cached column (BFS graph connectivity).
            // Single-member districts are always contiguous by definition — a jurisdiction
            // with no land neighbours is still a contiguous unit (like Puerto Rico or Hawaii).
            // Multi-member districts with NULL is_contiguous = not yet computed.
            $nonContiguous    = 0;
            $contiguous       = 0;
            $unchecked        = 0;
            $checkedCount     = 0;
            $contiguousPop    = 0;
            $nonContiguousPop = 0;
            $uncheckedPop     = 0;
            foreach ($leafRows as $r) {
                $mc  = $memberCountById[$r->id] ?? 1;
                $pop = (int) ($r->pop ?? 0);
                $checkedCount++;
                if ($mc <= 1) {
                    // Single-member: trivially contiguous
                    $contiguous++;
                    $contiguousPop += $pop;
                } elseif ($r->is_contiguous === null) {
                    $unchecked++;
                    $uncheckedPop += $pop;
                } elseif ((bool) $r->is_contiguous) {
                    $contiguous++;
                    $contiguousPop += $pop;
                } else {
                    $nonContiguous++;
                    $nonContiguousPop += $pop;
                }
            }
            $stats['contiguity'] = [
                'contiguous_count'    => $contiguous,
                'non_contiguous_count' => $nonContiguous,
                'unchecked_count'     => $unchecked,
                'checked_count'       => $checkedCount,
                'total'               => count($leafIds),
                'contiguous_pop'      => $contiguousPop,
                'non_contiguous_pop'  => $nonContiguousPop,
                'unchecked_pop'       => $uncheckedPop,
            ];
        }

        return $stats;
    }

    /**
     * Derive a short jurisdiction code for district naming.
     *
     * Rules:
     *   - ADM1 (country) with iso_code  → use iso_code directly (e.g. "USA", "GBR")
     *   - Multi-word name (ADM2+)        → first letter of each word, uppercase ("New York" → "NY")
     *   - Single-word name (ADM2+)       → first 3 characters, uppercase ("California" → "CAL")
     */
    private function makeShortCode(string $name, ?string $isoCode, int $admLevel): string
    {
        if ($admLevel === 1 && $isoCode) {
            return strtoupper($isoCode);
        }
        $words = preg_split('/\s+/', trim($name));
        if (count($words) > 1) {
            // Use mb_substr to safely extract the first character of each word —
            // $w[0] is byte-based and would truncate multi-byte UTF-8 characters.
            return strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), $words)));
        }
        // Use mb_substr to safely extract the first 3 characters —
        // substr($name, 0, 3) is byte-based and truncates multi-byte sequences
        // (e.g. "Jhārkhand" where ā = 2 bytes → substr produces invalid UTF-8 "JH\xc4").
        return strtoupper(mb_substr($name, 0, 3));
    }

    /**
     * Resolve which district map to use for a given legislature browser request.
     *
     * Priority:
     *   1. $requestedMapId — explicitly passed via URL ?map= or request body map_id
     *   2. The legislature's 'active' map
     *   3. The most recently created non-archived map (newest draft)
     *   4. null — no maps exist yet (backward compat: queries run without map_id filter)
     */

    /**
     * Flush revealed.geojson cache for a district's scope and every ancestor scope.
     *
     * When a district changes at scope S, the revealed layer for S and every ancestor
     * of S (up to the root) must be invalidated — each ancestor scope may display S's
     * sub-districts via the Branch 1 / Branch 2 SQL in revealedGeoJson().
     */
    private function flushRevealedCache(string $legislature_id, ?string $mapId, ?string $districtScopeId): void
    {
        if (!$districtScopeId) return;
        $mapKey = $mapId ?? 'null';
        // Walk the full ancestry in one CTE query instead of one query per level.
        $ancestorRows = DB::select("
            WITH RECURSIVE anc AS (
                SELECT id, parent_id FROM jurisdictions WHERE id = ? AND deleted_at IS NULL
                UNION ALL
                SELECT j.id, j.parent_id FROM jurisdictions j
                JOIN anc ON j.id = anc.parent_id WHERE j.deleted_at IS NULL
            )
            SELECT id FROM anc
        ", [$districtScopeId]);
        foreach ($ancestorRows as $row) {
            Cache::tags(["revealed.{$legislature_id}.{$mapKey}.{$row->id}"])->flush();
        }
    }

    private function getMapId(string $legislature_id, ?string $requestedMapId): ?string
    {
        if ($requestedMapId) {
            // Validate it belongs to this legislature (ignore soft-deleted maps)
            $exists = DB::table('legislature_district_maps')
                ->where('id', $requestedMapId)
                ->where('legislature_id', $legislature_id)
                ->whereNull('deleted_at')
                ->exists();
            if ($exists) {
                return $requestedMapId;
            }
        }

        // Active map first
        $active = DB::table('legislature_district_maps')
            ->where('legislature_id', $legislature_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->value('id');
        if ($active) {
            return $active;
        }

        // Newest non-archived map
        $draft = DB::table('legislature_district_maps')
            ->where('legislature_id', $legislature_id)
            ->where('status', '!=', 'archived')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->value('id');

        return $draft ?: null;
    }

    /**
     * Count total constitutional flags across all flag categories.
     *
     * Used when saving map summaries so the comparison panel can show counts
     * without re-running computeValidationFlags() on every page load.
     */
    private function flagCount(array $flags): int
    {
        return ($flags['cap'] !== null ? 1 : 0)
            + count($flags['floor_exceptions'] ?? [])
            + count($flags['deep_overages'] ?? [])
            + count($flags['deep_unevenness'] ?? [])
            + count($flags['incomplete_scopes'] ?? []);
    }

    private function toleranceForZoom(int $zoom): float
    {
        // One pixel in degrees at the given Leaflet zoom (tile size 256px, WGS84).
        // zoom 8 → ~0.0055°   zoom 10 → ~0.0014°   zoom 14 → ~0.000085°
        // Capped at 0.01° (the original fixed tolerance) so that zoom-adaptive never
        // degrades quality below the baseline — it can only improve it at zoom ≥ 8.
        // At zoom ≤ 7 the formula gives ≥ 0.011°, so the cap always applies there.
        return max(min(360.0 / (256.0 * (2 ** $zoom)), 0.01), 0.00005);
    }
}
