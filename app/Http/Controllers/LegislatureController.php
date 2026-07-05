<?php

namespace App\Http\Controllers;

use App\Services\ConstitutionalDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * @see App\Services\ConstitutionalDefaults
 *
 * Constitutional constants flow through ConstitutionalDefaults:
 *   - floor / ceiling — `legislature_min_seats` / `legislature_max_seats`
 *   - sizing law      — `legislature_sizing_law` (default cube_root)
 *
 * Derived thresholds used by the district mapper:
 *   - giant boundary   = ceiling + 0.5  (frac ≥ this triggers subdivision)
 *   - floor boundary   = floor           (composite minimum)
 *   - floor override   = floor − 0.5    (frac < this means a floor-override flag)
 *
 * With the default 5/9 settings these resolve to 9.5 / 5.0 / 4.5 (matching the
 * historical alpha-build literals). With operator-set values like 3/7 they
 * resolve to 7.5 / 3.0 / 2.5. The literal substitution shipped in this PR.
 */
class LegislatureController extends Controller
{
    /**
     * WI-B3 — the districting algorithm cluster (runAutoCompositeForScope,
     * recomputeDistrict, seat-budget math, mass-progress publishing) moved
     * mechanically to DistrictingService. The methods below delegate with
     * identical signatures; all call sites in this controller are unchanged.
     */
    public function __construct(private readonly \App\Services\DistrictingService $districting)
    {
    }

    /** @see \App\Services\DistrictingService::thresholds() */
    private function thresholds(string $jurisdictionId): array
    {
        return $this->districting->thresholds($jurisdictionId);
    }

    /** @see \App\Services\DistrictingService::computeSeatBudget() */
    private function computeSeatBudget(string $jurisdictionId, string $legislatureId): ?int
    {
        return $this->districting->computeSeatBudget($jurisdictionId, $legislatureId);
    }

    /** @see \App\Services\DistrictingService::computeNonGiantQuota() */
    private function computeNonGiantQuota(
        array $allChildren,
        float $fullQuota,
        int   $seatBudget,
        int   $effectivePop,
        float $giantThreshold,
        int   $floor
    ): float {
        return $this->districting->computeNonGiantQuota($allChildren, $fullQuota, $seatBudget, $effectivePop, $giantThreshold, $floor);
    }

    /** @see \App\Services\DistrictingService::recomputeDistrict() */
    private function recomputeDistrict(
        string $districtId,
        string $legislatureId,
        object $leg,
        bool   $skipSeatsUpdate = false
    ): void {
        $this->districting->recomputeDistrict($districtId, $legislatureId, $leg, $skipSeatsUpdate);
    }

    /** @see \App\Services\DistrictingService::runAutoCompositeForScope() */
    private function runAutoCompositeForScope(
        string  $legislature_id,
        object  $leg,
        string  $scopeId,
        bool    $clearExisting,
        int     $seatBudget,
        ?string $mapId = null
    ): array {
        return $this->districting->runAutoCompositeForScope($legislature_id, $leg, $scopeId, $clearExisting, $seatBudget, $mapId);
    }

    /** @see \App\Services\DistrictingService::publishMassProgress() */
    public function publishMassProgress(string $legislature_id, array $patch, bool $reset = false): void
    {
        $this->districting->publishMassProgress($legislature_id, $patch, $reset);
    }

    /**
     * Legislature index — WI-9 multi-legislature switcher.
     *
     * GET /legislatures
     *
     * One row per legislature: jurisdiction name/slug (the canonical
     * mapper address), adm level, seats per chamber type, status, district
     * count, and activation state (WF-JUR-01; null = no activation row —
     * the setup-founded root legislature is the expected case). Same auth
     * posture as show(): public, like the jurisdiction viewer.
     */
    public function index(): Response
    {
        // Per-chamber election affordances: the CURRENT election (latest
        // non-cancelled; live phases rank ahead of certified/final) and the
        // latest CERTIFIED election (drives the Results link). Two
        // DISTINCT ON picks keyed by legislature_id — /legislatures is the
        // hub for reaching every chamber's election surfaces.
        $currentElections = collect(DB::select(
            "SELECT DISTINCT ON (legislature_id) legislature_id, id, status
             FROM elections
             WHERE deleted_at IS NULL
               AND status <> 'cancelled'
               AND legislature_id IS NOT NULL
             ORDER BY legislature_id,
                      CASE WHEN status IN ('certified', 'final') THEN 1 ELSE 0 END,
                      created_at DESC"
        ))->keyBy('legislature_id');

        $certifiedElections = collect(DB::select(
            "SELECT DISTINCT ON (legislature_id) legislature_id, id
             FROM elections
             WHERE deleted_at IS NULL
               AND status <> 'cancelled'
               AND legislature_id IS NOT NULL
               AND certified_at IS NOT NULL
             ORDER BY legislature_id, certified_at DESC"
        ))->keyBy('legislature_id');

        $rows = DB::table('legislatures as l')
            ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
            ->leftJoin('jurisdiction_activations as a', function ($join) {
                $join->on('a.jurisdiction_id', '=', 'l.jurisdiction_id')
                     ->whereNull('a.deleted_at');
            })
            ->whereNull('l.deleted_at')
            ->orderBy('j.adm_level')
            ->orderByDesc(DB::raw('l.type_a_seats + l.type_b_seats'))
            ->get([
                'l.id',
                'l.type_a_seats',
                'l.type_b_seats',
                'l.status',
                'j.name as jurisdiction_name',
                'j.slug as jurisdiction_slug',
                'j.adm_level',
                'a.state as activation_state',
                'a.activated_at',
                DB::raw('(SELECT count(*) FROM legislature_districts ld
                          WHERE ld.legislature_id = l.id AND ld.deleted_at IS NULL) as district_count'),
                // FE-C2 — seated chamber detection: rows with current
                // members gain the Chamber link + seated/forming badge
                // (PHASE_C_DESIGN_frontend.md §B nav integration).
                DB::raw("(SELECT count(*) FROM legislature_members lm
                          WHERE lm.legislature_id = l.id
                            AND lm.status IN ('elected', 'seated')
                            AND lm.deleted_at IS NULL) as members_count"),
            ])
            ->map(function ($r) use ($currentElections, $certifiedElections) {
                $current = $currentElections->get($r->id);

                return [
                    'id'               => $r->id,
                    'jurisdiction'     => $r->jurisdiction_name,
                    'slug'             => $r->jurisdiction_slug,
                    'adm_level'        => (int) $r->adm_level,
                    'type_a_seats'     => (int) $r->type_a_seats,
                    'type_b_seats'     => (int) $r->type_b_seats,
                    'status'           => $r->status,
                    'members_count'    => (int) $r->members_count,
                    'district_count'   => (int) $r->district_count,
                    'activation_state' => $r->activation_state,
                    'activated_at'     => $r->activated_at
                        ? \Illuminate\Support\Carbon::parse($r->activated_at)->toIso8601String()
                        : null,
                    'election'         => $current === null ? null : [
                        'id'     => (string) $current->id,
                        'status' => $current->status,
                    ],
                    'results_election_id' => $certifiedElections->get($r->id)?->id,
                ];
            })
            ->values();

        return Inertia::render('Legislature/Index', [
            'surface'      => \App\Support\SurfaceMeta::for('legislature/index'),
            'legislatures' => $rows,
        ]);
    }

    /**
     * The legislature overview — what remains of the pre-split monolith
     * page (mockups-v3-wiring Phase 3e). Renders Legislature/Show: seats,
     * term, status, current members, a district-maps summary, and a
     * prominent door into the district mapper at
     * /legislatures/{legislature}/districts.
     *
     * GET /legislatures/{legislature_id}
     *
     * Back-compat shim: every pre-split mapper deep link carried mapper
     * query params (?scope= / ?map= / ?setup= / ?compare=) — including the
     * setup wizard's ?setup=1 handoff. Those requests 302 to the districts
     * route with the query string preserved, so nothing bookmarked breaks.
     */
    public function show(Request $request, string $legislature_id): Response|RedirectResponse
    {
        // Dual-accept the path param: a UUID (legacy / internal links) OR a
        // jurisdiction slug (canonical human-facing form) — the same
        // contract as districts() below.
        $uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        $legEnteredByUuid = (bool) preg_match($uuidRe, $legislature_id);
        if ($legEnteredByUuid) {
            $leg = DB::table('legislatures')
                ->where('id', $legislature_id)
                ->whereNull('deleted_at')
                ->first();
        } else {
            $leg = DB::table('legislatures')
                ->join('jurisdictions', 'jurisdictions.id', '=', 'legislatures.jurisdiction_id')
                ->where('jurisdictions.slug', $legislature_id)
                ->whereNull('legislatures.deleted_at')
                ->select('legislatures.*')
                ->first();
        }

        abort_if(!$leg, 404, 'Legislature not found.');
        $legislature_id = $leg->id;   // canonicalize to UUID for all downstream use

        $legSlug = DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('slug');
        $legPath = $legSlug ?? $leg->id;

        // Mapper deep-link shim (see the docblock above): forward the whole
        // query string to the districts surface.
        if ($request->query('scope') !== null || $request->query('map') !== null
            || $request->query('setup') !== null || $request->query('compare') !== null) {
            $qs = http_build_query($request->query());
            return redirect()->to("/legislatures/{$legPath}/districts" . ($qs !== '' ? "?{$qs}" : ''));
        }

        // Canonical-form redirect: UUID arrivals 302 to the pretty slug.
        if ($legEnteredByUuid && $legSlug) {
            return redirect()->to("/legislatures/{$legSlug}");
        }

        $jurisdiction = DB::table('jurisdictions')
            ->where('id', $leg->jurisdiction_id)
            ->whereNull('deleted_at')
            ->first(['id', 'name', 'slug']);

        // Current members (elected|seated) with the display name + district
        // label — the overview roster. Ordered by seat.
        $members = DB::table('legislature_members as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('legislature_districts as d', 'd.id', '=', 'm.district_id')
            ->where('m.legislature_id', $legislature_id)
            ->whereIn('m.status', ['elected', 'seated'])
            ->whereNull('m.deleted_at')
            ->orderBy('m.seat_no')
            ->get([
                'm.id', 'm.seat_no', 'm.status', 'm.seated_on', 'm.term_ends_on',
                'u.name as user_name', 'u.display_name as user_display_name',
                'd.district_number',
            ])
            ->map(fn ($m) => [
                'id'             => (string) $m->id,
                'seat_no'        => $m->seat_no !== null ? (int) $m->seat_no : null,
                'name'           => $m->user_display_name ?: ($m->user_name ?: '—'),
                'district_label' => $m->district_number !== null ? "District {$m->district_number}" : '—',
                'status'         => $m->status,
                'seated_on'      => $m->seated_on,
                'term_ends_on'   => $m->term_ends_on,
            ])
            ->values()
            ->all();

        // District-map summary — count + the active map (with its district
        // count) power the "Districts & maps" card.
        $mapsTotal = DB::table('legislature_district_maps')
            ->where('legislature_id', $legislature_id)
            ->whereNull('deleted_at')
            ->count();
        $activeMap = DB::table('legislature_district_maps')
            ->where('legislature_id', $legislature_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first(['id', 'name', 'status']);
        $activeMapDistricts = $activeMap
            ? DB::table('legislature_districts')
                ->where('legislature_id', $legislature_id)
                ->where('map_id', $activeMap->id)
                ->whereNull('deleted_at')
                ->count()
            : 0;

        $speakerName = $leg->speaker_id
            ? DB::table('legislature_members as m')
                ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.id', $leg->speaker_id)
                ->value(DB::raw('COALESCE(u.display_name, u.name)'))
            : null;

        return Inertia::render('Legislature/Show', [
            'surface' => \App\Support\SurfaceMeta::for('legislature/overview'),
            'legislature' => [
                'id'             => (string) $leg->id,
                'slug'           => $legSlug,
                'name'           => $jurisdiction?->name ?? $legSlug ?? (string) $leg->id,
                'status'         => $leg->status,
                'term_number'    => $leg->term_number !== null ? (int) $leg->term_number : null,
                'term_starts_on' => $leg->term_starts_on,
                'term_ends_on'   => $leg->term_ends_on,
                'type_a_seats'   => (int) $leg->type_a_seats,
                'type_b_seats'   => (int) ($leg->type_b_seats ?? 0),
                'serving'        => count($members),
                'speaker_name'   => $speakerName,
                'chamber_seated' => count($members) > 0,
            ],
            'members' => $members,
            'maps' => [
                'total'  => $mapsTotal,
                'active' => $activeMap ? [
                    'id'             => (string) $activeMap->id,
                    'name'           => $activeMap->name,
                    'status'         => $activeMap->status,
                    'district_count' => $activeMapDistricts,
                ] : null,
            ],
            'districtsHref' => "/legislatures/{$legPath}/districts",
        ]);
    }

    /**
     * The district mapper — the legislature browser's Leaflet machine,
     * extracted VERBATIM from show() (mockups-v3-wiring Phase 3e; the page
     * component moved to Legislature/Districts.vue).
     *
     * GET /legislatures/{legislature_id}/districts[?scope={jurisdiction_id}]
     *
     * Scope defaults to the legislature's own parent jurisdiction.
     * Drill-down is achieved by passing ?scope=<child_jurisdiction_id>.
     */
    public function districts(Request $request, string $legislature_id): Response|RedirectResponse
    {
        // Dual-accept the path param: a UUID (legacy / internal links) OR a
        // jurisdiction slug (canonical human-facing form — parity with the
        // jurisdiction viewer at /jurisdictions/{slug}). A slug resolves to the
        // legislature via the jurisdiction it roots. After resolution we
        // canonicalize $legislature_id back to the UUID so every downstream
        // query + PDO binding stays unchanged.
        $uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        $legEnteredByUuid = (bool) preg_match($uuidRe, $legislature_id);
        if ($legEnteredByUuid) {
            $leg = DB::table('legislatures')
                ->where('id', $legislature_id)
                ->whereNull('deleted_at')
                ->first();
        } else {
            $leg = DB::table('legislatures')
                ->join('jurisdictions', 'jurisdictions.id', '=', 'legislatures.jurisdiction_id')
                ->where('jurisdictions.slug', $legislature_id)
                ->whereNull('legislatures.deleted_at')
                ->select('legislatures.*')
                ->first();
        }

        abort_if(!$leg, 404, 'Legislature not found.');
        $legislature_id = $leg->id;   // canonicalize to UUID for all downstream use

        // Scope: which level of the hierarchy to display. Defaults to the
        // legislature's own root jurisdiction (Earth, USA, etc.). Accepts a
        // UUID or a jurisdiction slug, same as the path param.
        $scopeParam   = $request->query('scope');
        $scopeWasUuid = $scopeParam !== null && (bool) preg_match($uuidRe, $scopeParam);
        if ($scopeParam !== null && !$scopeWasUuid) {
            $scopeId = DB::table('jurisdictions')
                ->where('slug', $scopeParam)
                ->whereNull('deleted_at')
                ->value('id') ?? $leg->jurisdiction_id;
        } else {
            $scopeId = $scopeParam ?: $leg->jurisdiction_id;
        }

        // Resolve the district map to display (URL ?map= param → active → newest draft).
        $mapId = $this->getMapId($legislature_id, $request->query('map'));

        $scope = DB::table('jurisdictions')
            ->where('id', $scopeId)
            ->whereNull('deleted_at')
            ->first();

        abort_if(!$scope, 404, 'Scope jurisdiction not found.');

        // Canonical legislature slug (= its root jurisdiction's slug) + a helper
        // that builds the canonical /legislatures/{slug}/districts?scope={slug}
        // URL for any scope id, preserving map/setup query params. Used by the
        // giant-guard and canonical-form redirects below so addresses always
        // read cleanly.
        $legSlug = DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('slug');
        $canonicalUrl = function (?string $sId) use ($legSlug, $leg, $request) {
            $q = [];
            if ($sId && $sId !== $leg->jurisdiction_id) {
                $sSlug = DB::table('jurisdictions')->where('id', $sId)->value('slug');
                if ($sSlug) $q['scope'] = $sSlug;
            }
            foreach (['map', 'setup'] as $k) {
                $v = $request->query($k);
                if ($v !== null && $v !== '') $q[$k] = $v;
            }
            return '/legislatures/' . $legSlug . '/districts' . (count($q) ? '?' . http_build_query($q) : '');
        };

        // A ?map= that no longer resolves (a deleted draft, a foreign id) must
        // not linger in the address bar while the page silently renders the
        // fallback — the URL and the MAP selector would disagree, and every
        // canonicalUrl-built link would carry the stale param forward forever.
        // Redirect once onto the RESOLVED map (or bare, if none). Runs before
        // the guards below so their redirects never re-embed the dead param.
        $mapParam = $request->query('map');
        if ($mapParam !== null && $mapParam !== '' && $mapParam !== $mapId) {
            $q = $request->query();
            unset($q['map']);
            if ($mapId !== null) {
                $q['map'] = $mapId;
            }
            return redirect()->to($request->url() . (count($q) ? '?' . http_build_query($q) : ''));
        }

        // Guard: the scope must live INSIDE this legislature's own root subtree.
        // Without it, breadcrumb/step-up links (or a hand-edited URL) can walk a
        // legislature's mapper onto an ANCESTOR jurisdiction — the giant guard
        // below can't catch that (an ancestor's fractional seats are always
        // astronomical), and every Webster-share figure on the page becomes
        // nonsense ("7,677,127 seats to assign" on a 32-seat legislature).
        // Out-of-subtree scopes land back at the root scope, map param kept.
        if ($scopeId !== $leg->jurisdiction_id) {
            $inSubtree = false;
            $cursor = $scope->parent_id;
            for ($hops = 0; $cursor !== null && $hops < 32; $hops++) {
                if ($cursor === $leg->jurisdiction_id) {
                    $inSubtree = true;
                    break;
                }
                $cursor = DB::table('jurisdictions')->where('id', $cursor)->whereNull('deleted_at')->value('parent_id');
            }
            if (! $inSubtree) {
                return redirect()->to($canonicalUrl(null));
            }
        }

        // Root jurisdiction population — always the legislature's own jurisdiction (e.g. Earth).
        // Used to compute proportional entitlements consistently across all drill-down levels.
        $rootPop = (int) DB::table('jurisdictions')
            ->where('id', $leg->jurisdiction_id)
            ->value('population');
        $rootPop = max($rootPop, 1);

        // Constitutional thresholds — resolved from the legislature's root
        // jurisdiction's constitutional_settings. Substituting these for the
        // legacy hardcoded 9.5 / 5.0 / 4.5 literals lets operator-set floor
        // and ceiling values actually flow through into the district mapper.
        // With default 5/9 settings these come out 9.5 / 5.0 / 4.5 (matching
        // the legacy alpha-build behavior). With operator-set 3/7 they come
        // out 7.5 / 3.0 / 2.5.
        $floor          = ConstitutionalDefaults::floor($leg->jurisdiction_id);
        $ceiling        = ConstitutionalDefaults::ceiling($leg->jurisdiction_id);
        $giantThreshold = ConstitutionalDefaults::giantThreshold($leg->jurisdiction_id);
        $floorBoundary  = ConstitutionalDefaults::floorBoundary($leg->jurisdiction_id);
        $floorOverride  = ConstitutionalDefaults::floorOverrideBoundary($leg->jurisdiction_id);

        // Guard: non-root scopes must be giants (fractional_seats >= giant threshold at root quota).
        // Prevents URL-based access to non-giant sub-scopes (indivisible jurisdictions).
        if ($scopeId !== $leg->jurisdiction_id) {
            $scopeFrac = (int) $scope->population * (int) $leg->type_a_seats / $rootPop;
            if ($scopeFrac < $giantThreshold) {
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
                    if ($parentFrac >= $giantThreshold) {
                        $redirectScopeId = $parentId;
                        break;
                    }
                    $parentId = $parentRow->parent_id;
                }
                return redirect()->to($canonicalUrl($redirectScopeId));
            }
        }

        // Canonical-form redirect: if the operator arrived via a UUID (legacy
        // link, or a UUID scope param), 302 to the pretty slug form so the
        // address bar matches the jurisdiction viewer. Entering by slug skips
        // this. After the redirect both path + scope are slugs, so no loop.
        if ($legEnteredByUuid || $scopeWasUuid) {
            return redirect()->to($canonicalUrl($scopeId));
        }

        // Scope's rounded seat entitlement from the root legislature.
        // At root scope: just the legislature's own type_a_seats (e.g. 1999).
        // At non-root scope: use SUM(direct_children.population) as denominator — the same
        // population base that runAutoCompositeForScope() uses for $scopeBudget.  This
        // eliminates the "28 vs 29" class of mismatches that arise when a parent's stored
        // population ≠ sum of its direct children (data-quality variation between
        // geoBoundaries boundary levels).
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
            // Seat budget at non-root scopes via the gated cascade. Path 2
            // (district lookup) covers composite members; Path 3 (cascade)
            // walks the parent chain for giants. Returns NULL only if the
            // legislature row can't be loaded — fall back to the old
            // proportional approximation in that degenerate case.
            $scopeSeats = $this->computeSeatBudget($scopeId, $legislature_id)
                ?? max($floor, (int) round($effectivePop * (int) $leg->type_a_seats / $rootPop));
        }

        // Display quota: effectivePop / scope_entitlement.
        // Uses the same population base as $scopeSeats so the displayed quota matches
        // what the ETL used when seeding districts (e.g. Philippines: SUM(ADM1 pops)/29).
        $quota = max($effectivePop, 1) / max($scopeSeats, 1);
        $isRootScope = ($scopeId === $leg->jurisdiction_id);

        // ── Ancestor chain (cheap, needed by both the render call AND the
        // heavy block's district-name labeling) ────────────────────────────────
        // STOPS at the legislature's own root: the chain must never climb into
        // ancestor jurisdictions ABOVE the legislature (the breadcrumb links,
        // the wizard's ↑ control, and the ancestors[0]=root / ancestors[1]=
        // first-child labeling convention all assume the legislature-rooted
        // chain — an over-walk to the planetary root let the mapper step onto
        // out-of-subtree scopes and skewed non-Earth district short codes).
        $ancestors = DB::select("
            WITH RECURSIVE anc AS (
                SELECT id, name, slug, parent_id, iso_code, adm_level
                FROM jurisdictions WHERE id = :start_id
                UNION ALL
                SELECT j.id, j.name, j.slug, j.parent_id, j.iso_code, j.adm_level
                FROM jurisdictions j
                JOIN anc ON j.id = anc.parent_id
                WHERE j.deleted_at IS NULL
                  AND anc.id <> :root_id
            )
            SELECT id, name, slug, iso_code, adm_level FROM anc
        ", ['start_id' => $scopeId, 'root_id' => $leg->jurisdiction_id]);
        // Reverse so we get root → current scope
        $ancestors = array_reverse($ancestors);

        // ── Heavy data block — wrapped in a memoized closure ───────────────────
        // The 788 ms recursive CTE for children + assemble-districts + flags +
        // stats computation collectively pushed initial page render north of
        // 1 s, leaving the operator staring at a blank screen after every code
        // change → restart cycle. Wrap the whole block in a closure and defer
        // its execution via Inertia::defer (v2): the initial render returns the
        // cheap header/scope/maps data immediately, the page mounts, and Vue
        // auto-issues a partial reload to populate the heavy props in the
        // background. The 5 defer closures share this memoized loader so the
        // backend does the work exactly once per partial reload.
        $heavyCache = null;
        $loadHeavy = function () use (
            &$heavyCache,
            $legislature_id, $scopeId, $mapId, $leg,
            $scope, $scopeSeats, $rootPop, $effectivePop, $isRootScope,
            $giantThreshold, $floor, $quota, $ancestors,
        ) {
            if ($heavyCache !== null) return $heavyCache;

        // Children of scope with their fractional seats and current district assignment
        $mapFilter  = $mapId !== null ? 'AND ld.map_id = :map_id' : '';
        $mapBindings = $mapId !== null ? ['map_id' => $mapId] : [];

        // child_assigned_seats: seats committed inside each child's subtree at any depth.
        // A correlated one-level subquery misses grandchildren (e.g. Earth → India →
        // states → sub-state districts at adm_level 4+). A WITH RECURSIVE CTE walks
        // all descendants (depth-limited to 5 levels) so that Earth scope correctly
        // sums UP/Maharashtra's sub-districts that contain deep-hierarchy members.
        // PDO does not allow reusing named parameters — scope_id_r / leg_id2 / map_id2 are
        // distinct aliases for the same values used in the outer query.
        $childMapFilter2 = $mapId !== null ? 'AND ld2.map_id = :map_id2' : '';
        $childMapFilter3 = $mapId !== null ? 'AND ld3.map_id = :map_id3' : '';
        $childMapBindings = $mapId !== null ? ['map_id2' => $mapId, 'map_id3' => $mapId] : [];

        $children = DB::select("
            WITH RECURSIVE giant_children AS (
                -- Only giant children (frac >= giant_threshold) ever have sub-districts;
                -- non-giants are always composited at this scope level so their
                -- child_assigned_seats is always 0. Scoping the seed to giants only reduces
                -- CTE rows from millions (all descendants of all ~232 countries) to just the
                -- subtrees of the giant countries.
                SELECT id
                FROM   jurisdictions
                WHERE  parent_id  = :scope_id_r
                  AND  deleted_at IS NULL
                  AND  (CAST(population AS numeric) * :total_seats_c / :root_pop_c) >= :giant_threshold_c
            ),
            desc_tree AS (
                -- Seed: giant children only (root_child_id tracks which giant each
                -- descendant belongs to so we can GROUP BY it in child_committed)
                SELECT id, id AS root_child_id, 0 AS lvl
                FROM   giant_children
                UNION ALL
                -- Recurse: one level deeper, capped at lvl 4 (covers 4 levels of
                -- descent from a giant country — e.g. country → state → county → local)
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
                -- The second branch adds DRAWN districts (leaf-giant subdivisions):
                -- their memberships carry a subdivision_id and no jurisdiction, so
                -- the jurisdiction-side join alone leaves every drawn seat invisible
                -- to the parent scope's counters. The UNION dedupes by district id
                -- (a district is never reachable both ways).
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
                    UNION
                    SELECT DISTINCT ld3.id, ld3.seats, dt3.root_child_id
                    FROM   desc_tree dt3
                    JOIN   district_subdivisions ds3
                               ON ds3.parent_jurisdiction_id = dt3.id
                              AND ds3.deleted_at IS NULL
                    JOIN   legislature_district_jurisdictions ldj3
                               ON ldj3.subdivision_id = ds3.id
                    JOIN   legislature_districts ld3
                               ON ld3.id = ldj3.district_id
                              AND ld3.legislature_id = :leg_id3
                              AND ld3.deleted_at IS NULL
                              {$childMapFilter3}
                ) distinct_d
                GROUP BY distinct_d.root_child_id
            )
            SELECT DISTINCT ON (j.id)
                j.id,
                j.name,
                j.slug,
                j.adm_level,
                j.population,
                -- type_a_apportioned populated via computeSeatBudget() in PHP
                -- after this select returns. Cheaper than the prior inline
                -- subselect, and uses the gated cascade so giants resolve
                -- correctly (lookup-by-member misses for giants).
                NULL                                              AS type_a_apportioned,
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
            'quota'              => $quota,
            'leg_id'             => $legislature_id,
            'leg_id2'            => $legislature_id,
            'leg_id3'            => $legislature_id,
            'scope_id'           => $scopeId,
            'scope_id_r'         => $scopeId,
            'total_seats_c'      => (int) $leg->type_a_seats,
            'root_pop_c'         => $rootPop,
            'giant_threshold_c'  => $giantThreshold,
        ], $mapBindings, $childMapBindings));

        // Re-sort in PHP after DISTINCT ON forces ORDER BY j.id above.
        usort($children, fn($a, $b) => $b->population - $a->population);

        // Populate type_a_apportioned via the gated computeSeatBudget cascade.
        // For non-giants already members of a composite district at this scope
        // (the common case), this is a single indexed query per child that
        // hits the in-memory memo on repeat calls. Giants take Path 3 and walk
        // the parent chain up to the legislature's root — the only place the
        // recursion actually fires.
        foreach ($children as $c) {
            $c->type_a_apportioned = $this->computeSeatBudget($c->id, $legislature_id);
        }

        // Drawn-district seats per DIRECT child (Phase 5e): a childless leaf
        // giant holds its seats on district_subdivisions rows, keyed by
        // parent_jurisdiction_id — one grouped sum over the (tiny, indexed)
        // subdivisions table gives every leaf-giant child its progress figure.
        // Joined through live legislature_districts so a retired plan's rows
        // never count. Emitted as the ADDITIVE `drawn_seats` children prop.
        $drawnSeatsByChild = [];
        if (count($children) > 0) {
            $drawnSeatsByChild = DB::table('district_subdivisions as ds')
                ->join('legislature_district_jurisdictions as ldj', 'ldj.subdivision_id', '=', 'ds.id')
                ->join('legislature_districts as ld', 'ld.id', '=', 'ldj.district_id')
                ->whereIn('ds.parent_jurisdiction_id', array_map(fn ($c) => $c->id, $children))
                ->whereNull('ds.deleted_at')
                ->whereNull('ld.deleted_at')
                ->where('ld.legislature_id', $legislature_id)
                ->when($mapId !== null, fn ($q) => $q->where('ld.map_id', $mapId))
                ->groupBy('ds.parent_jurisdiction_id')
                ->selectRaw('ds.parent_jurisdiction_id AS cid, SUM(ld.seats)::int AS s')
                ->pluck('s', 'cid')
                ->all();
        }

        // ── Non-giant quota adjustment ────────────────────────────────────────
        // When giants are present, their true fractional seats (non-integer) are rounded
        // to integer locked-seat values. Using the full quota for non-giant fracs
        // causes them to sum to a non-integer value instead of exactly (scopeSeats − giantSeats).
        // Example: Indonesia 70 seats, WJ 12.63→13, EJ 10.57→11; non-giant fracs sum to
        // 46.83 with full quota instead of 46. The non-giant quota fixes this exactly.
        // Giants keep their full-quota frac for Vue GIANT_THRESHOLD identification
        // (>= giant_threshold).
        $ngQuota = $this->computeNonGiantQuota(
            $children,
            $quota,         // full quota = effectivePop / scopeSeats
            $scopeSeats,
            $effectivePop,
            $giantThreshold,
            $floor
        );
        if ($ngQuota !== $quota) {
            foreach ($children as &$c) {
                if ((float) $c->fractional_seats < $giantThreshold) {
                    $c->fractional_seats = round((float) $c->population / max($ngQuota, 1), 4);
                }
            }
            unset($c);
        }

        // Districts with full member data — one row per district-member pair at this scope.
        // Grouped in PHP so each district gets a `members` array with IDs for map highlighting.
        // color_index is NOT pulled here — it's computed below from the
        // adjacency graph of the result set (colorIndicesForDistricts()).
        $dmRows = DB::select("
            SELECT
                ld.id               AS district_id,
                ld.seats,
                ld.floor_override,
                ld.status,
                ld.district_number  AS dnum,
                ld.actual_population AS district_pop,
                -- Compute fractional live from actual_population / current-scope quota
                -- so district Rep always matches member Rep (both use the same quota).
                -- Using ld.fractional_seats (stored) caused discrepancies when
                -- district.jurisdiction_id's population base differed from the viewed scope.
                ROUND(CAST(ld.actual_population AS numeric) / NULLIF(CAST(:quota_d AS numeric), 0), 4) AS district_frac,
                ld.convex_hull_ratio,
                ld.is_contiguous,
                j.id                AS jid,
                j.name              AS jname,
                j.population        AS jpop,
                j.iso_code          AS jiso,
                j.adm_level         AS jadm,
                ROUND(CAST(j.population AS numeric) / :quota, 4) AS jfrac,
                (SELECT COUNT(*) FROM jurisdictions c WHERE c.parent_id = j.id AND c.deleted_at IS NULL)
                                    AS jchild_count,
                j_dscope.name       AS district_scope_name,
                j_dscope.iso_code   AS district_scope_iso,
                j_dscope.adm_level  AS district_scope_adm,
                j_dscope.population AS district_scope_pop,
                (SELECT COUNT(*) FROM jurisdictions jcc WHERE jcc.parent_id = j_dscope.id AND jcc.deleted_at IS NULL)
                                    AS district_scope_child_count,
                j_dspar.name       AS district_scope_parent_name,
                j_dspar.iso_code   AS district_scope_parent_iso,
                j_dspar.adm_level  AS district_scope_parent_adm,
                (j_dspar.parent_id IS NULL) AS district_scope_gp_is_root
            FROM legislature_districts ld
            JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
            JOIN jurisdictions j
                ON j.id = ldj.jurisdiction_id
               AND j.parent_id = :scope_id
               AND j.deleted_at IS NULL
            JOIN jurisdictions j_dscope ON j_dscope.id = ld.jurisdiction_id AND j_dscope.deleted_at IS NULL
            LEFT JOIN jurisdictions j_dspar ON j_dspar.id = j_dscope.parent_id AND j_dspar.deleted_at IS NULL
            WHERE ld.legislature_id = :leg_id
              AND ld.deleted_at IS NULL
              {$mapFilter}
            ORDER BY ld.seats DESC, j.population DESC
        ", array_merge([
            'quota'    => $ngQuota,   // non-giant quota: member fracs (jfrac) sum to ngBudget
            'quota_d'  => $ngQuota,   // PDO disallows duplicate named params; alias for district_frac
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
                    'color_index'      => 0,   // overlaid below from greedy coloring
                    'district_number'  => (int) $row->dnum,
                    'population'       => (int) $row->district_pop,
                    'fractional_seats' => (float) $row->district_frac,
                    'convex_hull_ratio' => $row->convex_hull_ratio !== null ? round((float) $row->convex_hull_ratio, 3) : null,
                    'is_contiguous'     => $row->is_contiguous !== null ? (bool) $row->is_contiguous : null,
                    'has_integrity'     => !(($quota > 0 ? (float) $row->district_scope_pop / $quota : 0) >= $giantThreshold && (int) $row->district_scope_child_count === 0),
                    'scope_iso'        => $row->district_scope_iso,
                    'scope_adm'        => (int) $row->district_scope_adm,
                    'scope_name'       => $row->district_scope_name,
                    'parent_iso'       => $row->district_scope_parent_iso,
                    'parent_adm'       => $row->district_scope_parent_adm !== null ? (int) $row->district_scope_parent_adm : null,
                    'parent_name'      => $row->district_scope_parent_name,
                    'gp_is_root'       => (bool) $row->district_scope_gp_is_root,
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
        // $ancestors is now computed BEFORE the heavy closure (cheap query,
        // needed by both the closure's district-name labeling AND the cheap
        // Inertia render call). Captured via the closure's use() clause above.

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
            } else {
                // Mirror revealedDistrictName() — build prefix from district's own scope ancestry.
                // scope = the jurisdiction ld.jurisdiction_id (e.g. UP for India-scope districts)
                // parent = scope's parent (e.g. India)
                // gp_is_root = parent.parent_id IS NULL (true when parent is Earth/root → skip parent code)
                //
                // Earth-scope (scope=CHN, parent=Earth→gp_is_root=true): prefix=["CHN"] → "CHN 01"
                // India-scope (scope=UP, parent=India→gp_is_root=false): prefix=["IND","UP"] → "IND UP 01"
                // USA-scope   (scope=CA, parent=USA→gp_is_root=false):  prefix=["USA","CA"] → "USA CA 01"
                $prefix = [];
                if (!$d['gp_is_root'] && ($d['parent_iso'] || $d['parent_name'])) {
                    $prefix[] = $this->makeShortCode($d['parent_name'] ?? '', $d['parent_iso'], $d['parent_adm'] ?? 0);
                }
                $prefix[] = $this->makeShortCode($d['scope_name'] ?? '', $d['scope_iso'], $d['scope_adm']);
                $num       = str_pad($d['district_number'], 2, '0', STR_PAD_LEFT);
                $d['name'] = implode(' ', $prefix) . ' ' . $num;
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

        // ── Drawn (subdivision) districts at THIS scope — Phase 5e ────────────
        // A childless leaf giant's districts carry their members as
        // district_subdivisions (subdivision_id memberships, no jurisdiction
        // rows), so the member-joined $dmRows above never sees them and the
        // sidebar/counters went blind at the leaf scope. Fetch them here and
        // merge into the same district list — ADDITIVE prop shape (extra
        // label/subdivision_id/method/deviation_pct keys; everything the
        // composite rows carry is present too), pinned by MonolithSplitTest.
        // Appended AFTER the composite fractional recompute above so that
        // local re-quota never mangles a drawn district's own raster-derived
        // share. Empty everywhere except a leaf-giant scope by construction.
        $drawnMapFilter = $mapId !== null ? 'AND ld.map_id = :map_id_dr' : '';
        $drawnBindings  = $mapId !== null ? ['map_id_dr' => $mapId] : [];
        $drawnRows = DB::select("
            SELECT
                ld.id                AS district_id,
                ld.seats,
                ld.floor_override,
                ld.status,
                ld.district_number   AS dnum,
                ld.convex_hull_ratio,
                ld.is_contiguous,
                ds.id                AS subdivision_id,
                ds.label,
                ds.population        AS ds_pop,
                j_g.name             AS giant_name,
                j_g.iso_code         AS giant_iso,
                j_g.adm_level        AS giant_adm,
                j_gp.name            AS gpar_name,
                j_gp.iso_code        AS gpar_iso,
                j_gp.adm_level       AS gpar_adm,
                (j_gp.parent_id IS NULL) AS gp_is_root
            FROM legislature_districts ld
            JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
                AND ldj.subdivision_id IS NOT NULL
            JOIN district_subdivisions ds ON ds.id = ldj.subdivision_id
                AND ds.deleted_at IS NULL
            JOIN jurisdictions j_g ON j_g.id = ld.jurisdiction_id
            LEFT JOIN jurisdictions j_gp ON j_gp.id = j_g.parent_id
            WHERE ld.legislature_id = :leg_id_dr
              AND ld.jurisdiction_id = :scope_id_dr
              AND ld.deleted_at IS NULL
              {$drawnMapFilter}
            ORDER BY ld.district_number
        ", array_merge([
            'leg_id_dr'  => $legislature_id,
            'scope_id_dr' => $scopeId,
        ], $drawnBindings));

        foreach ($drawnRows as $row) {
            $pop  = (int) $row->ds_pop;
            $seats = (int) $row->seats;
            $frac = $quota > 0 ? round($pop / $quota, 4) : 0.0;
            $districtMap[$row->district_id] = [
                'id'                => $row->district_id,
                'seats'             => $seats,
                'floor_override'    => (bool) $row->floor_override,
                'status'            => $row->status,
                'color_index'       => 0,   // overlaid below, same as composites
                'district_number'   => (int) $row->dnum,
                'population'        => $pop,
                'fractional_seats'  => $frac,
                'convex_hull_ratio' => $row->convex_hull_ratio !== null ? round((float) $row->convex_hull_ratio, 3) : null,
                'is_contiguous'     => $row->is_contiguous !== null ? (bool) $row->is_contiguous : true,
                // A drawn piece IS the in-band answer for the leaf giant — the
                // same posture the revealed layer's Branch 3 emits.
                'has_integrity'     => true,
                'scope_iso'         => $row->giant_iso,
                'scope_adm'         => (int) $row->giant_adm,
                'scope_name'        => $row->giant_name,
                'parent_iso'        => $row->gpar_iso,
                'parent_adm'        => $row->gpar_adm !== null ? (int) $row->gpar_adm : null,
                'parent_name'       => $row->gpar_name,
                'gp_is_root'        => (bool) $row->gp_is_root,
                'name'              => $row->label,
                // Additive keys for the leaf sidebar: the human label, the
                // subdivision id paired with the district id for deletion, the
                // method marker, and per-seat deviation off the local quota.
                'label'             => $row->label,
                'subdivision_id'    => $row->subdivision_id,
                'method'            => 'drawn',
                'deviation_pct'     => ($seats > 0 && $quota > 0)
                    ? round(($pop / $seats - $quota) / $quota * 100, 2)
                    : null,
                'members'           => [],
            ];
        }

        // Overlay adjacency-aware colors from the legislature-wide coloring.
        // Using legislatureColorMap (not just this scope's districts) lets
        // sidebar dot colors stay consistent with revealedGeoJson's map fills
        // for districts at different ADM levels viewed together at one scope.
        $colorMap = $this->legislatureColorMap($legislature_id, $mapId);
        foreach ($districtMap as $did => &$d) {
            $d['color_index'] = $colorMap[$did] ?? 0;
        }
        unset($d);

        $districts = array_values($districtMap);

        // Districts have no stored geometry — centroids are always null.
        // The revealed layer uses jurisdiction polygons (jurisdictions.geom) directly.
        foreach ($districts as &$d) {
            $d['centroid'] = null;
        }
        unset($d);

        $flags = $this->computeValidationFlags($legislature_id, $leg, $scopeId, $children, $districts, $mapId);
        $stats = $this->computeConstitutionalStats($legislature_id, $scopeId, $districts, $ngQuota, $mapId);

        // ── Drawn districts of DESCENDANT leaf giants — the ANCESTOR rows ────
        // The revealed layer already PAINTS a descendant giant's drawn pieces
        // at this scope (Branches 4/5 of revealedGeoJson) — without list rows
        // the operator saw color with no name. Reach is the exact Branch 4/5
        // parity: giants ONE and TWO levels below the scope, behind the same
        // giant-threshold gate; never deeper. Same additive row shape as the
        // leaf merge above.
        //
        // Appended AFTER flags/stats ON PURPOSE: a drawn district's seats
        // already reach this scope's counters through the child_committed
        // subdivision branch (child_assigned_seats / drawn_seats), so letting
        // these rows into the flag math would count each giant's budget twice.
        // fractional_seats / deviation_pct read the district's STORED
        // local-quota entitlement (its own giant's quota) — pricing a deeper
        // giant's piece against the viewed scope's quota would be meaningless.
        $ancDrawnFilter   = $mapId !== null ? 'AND ld.map_id = :map_id_anc' : '';
        $ancDrawnBindings = $mapId !== null ? ['map_id_anc' => $mapId] : [];
        $ancDrawnRows = DB::select("
            SELECT
                ld.id                AS district_id,
                ld.seats,
                ld.floor_override,
                ld.status,
                ld.district_number   AS dnum,
                ld.fractional_seats  AS ld_frac,
                ld.convex_hull_ratio,
                ld.is_contiguous,
                ds.id                AS subdivision_id,
                ds.label,
                ds.population        AS ds_pop,
                j_g.name             AS giant_name,
                j_g.iso_code         AS giant_iso,
                j_g.adm_level        AS giant_adm,
                j_gp.name            AS gpar_name,
                j_gp.iso_code        AS gpar_iso,
                j_gp.adm_level       AS gpar_adm,
                (j_gp.parent_id IS NULL) AS gp_is_root
            FROM legislature_districts ld
            JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
                AND ldj.subdivision_id IS NOT NULL
            JOIN district_subdivisions ds ON ds.id = ldj.subdivision_id
                AND ds.deleted_at IS NULL
            JOIN jurisdictions j_g ON j_g.id = ld.jurisdiction_id
                AND j_g.deleted_at IS NULL
            LEFT JOIN jurisdictions j_gp ON j_gp.id = j_g.parent_id
            WHERE ld.legislature_id = :leg_id_anc
              AND ld.deleted_at IS NULL
              {$ancDrawnFilter}
              AND (
                    -- giant ONE level below the scope (reveal Branch 4 reach)
                    (j_g.parent_id = :scope_anc1
                     AND (CAST(j_g.population AS numeric) * :seats_anc1 / :pop_anc1) >= :thr_anc1)
                    -- giant TWO levels below (reveal Branch 5 reach)
                    OR EXISTS (
                        SELECT 1 FROM jurisdictions j_mid
                         WHERE j_mid.id = j_g.parent_id
                           AND j_mid.parent_id = :scope_anc2
                           AND j_mid.deleted_at IS NULL
                           AND (CAST(j_g.population AS numeric) * :seats_anc2 / :pop_anc2) >= :thr_anc2
                    )
              )
            ORDER BY j_g.name, ld.district_number
        ", array_merge([
            'leg_id_anc' => $legislature_id,
            'scope_anc1' => $scopeId,
            'seats_anc1' => (int) $leg->type_a_seats,
            'pop_anc1'   => $rootPop,
            'thr_anc1'   => $giantThreshold,
            'scope_anc2' => $scopeId,
            'seats_anc2' => (int) $leg->type_a_seats,
            'pop_anc2'   => $rootPop,
            'thr_anc2'   => $giantThreshold,
        ], $ancDrawnBindings));

        foreach ($ancDrawnRows as $row) {
            $seats = (int) $row->seats;
            $frac  = round((float) $row->ld_frac, 4);
            $districts[] = [
                'id'                => $row->district_id,
                'seats'             => $seats,
                'floor_override'    => (bool) $row->floor_override,
                'status'            => $row->status,
                'color_index'       => $colorMap[$row->district_id] ?? 0,
                'district_number'   => (int) $row->dnum,
                'population'        => (int) $row->ds_pop,
                'fractional_seats'  => $frac,
                'convex_hull_ratio' => $row->convex_hull_ratio !== null ? round((float) $row->convex_hull_ratio, 3) : null,
                'is_contiguous'     => $row->is_contiguous !== null ? (bool) $row->is_contiguous : true,
                'has_integrity'     => true,
                'scope_iso'         => $row->giant_iso,
                'scope_adm'         => (int) $row->giant_adm,
                'scope_name'        => $row->giant_name,
                'parent_iso'        => $row->gpar_iso,
                'parent_adm'        => $row->gpar_adm !== null ? (int) $row->gpar_adm : null,
                'parent_name'       => $row->gpar_name,
                'gp_is_root'        => (bool) $row->gp_is_root,
                'name'              => $row->label,
                'label'             => $row->label,
                'subdivision_id'    => $row->subdivision_id,
                'method'            => 'drawn',
                'deviation_pct'     => ($seats > 0 && $frac > 0)
                    ? round(($frac / $seats - 1) * 100, 2)
                    : null,
                'members'           => [],
                'centroid'          => null,
            ];
        }

            // ── End of deferred heavy block ───────────────────────────────────────
            // Package the heavy results into the shared cache. The 5 defer
            // closures in Inertia::render() pull individual props from this
            // structure — all share one backend pass.
            $heavyCache = [
                'children'  => array_map(fn($c) => [
                    'id'               => $c->id,
                    'name'             => $c->name,
                    'slug'             => $c->slug,
                    'adm_level'        => $c->adm_level,
                    'population'       => (int) $c->population,
                    // At non-root scopes, giant children (frac >= giant_threshold) display
                    // their integer allocation (round of local-quota frac) so that
                    // composite_sum + giant_integers = scopeSeats exactly. Root scope keeps
                    // raw fractionals (e.g. India 357.94).
                    'fractional_seats' => !$isRootScope && (float) $c->fractional_seats >= $giantThreshold
                        ? (float) round((float) $c->fractional_seats)
                        : (float) $c->fractional_seats,
                    'district_id'      => $c->district_id,
                    'district_seats'   => $c->district_seats !== null ? (int) $c->district_seats : null,
                    'floor_override'   => (bool) $c->floor_override,
                    'child_count'          => (int) $c->child_count,
                    'child_assigned_seats' => (int) ($c->child_assigned_seats ?? 0),
                    'type_a_apportioned'   => $c->type_a_apportioned !== null ? (int) $c->type_a_apportioned : null,
                    // Seats committed as DRAWN districts inside this child (a
                    // childless leaf giant) — lets the sidebar show the giant's
                    // progress toward its budget without drilling in.
                    'drawn_seats'          => (int) ($drawnSeatsByChild[$c->id] ?? 0),
                ], $children),
                'districts' => $districts,
                'flags'     => $flags,
                'stats'     => $stats,
                'quota'     => round($ngQuota),
            ];
            return $heavyCache;
        };

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

        // Whether the current user could actually FILE F-ELB-008 here: the
        // same gate pair the handler runs — the SETUP-context founder exception
        // (an operator drawing map v1 while the jurisdiction has no human-seated
        // active board; Art. II bootstrap posture), else their OWN seated row on
        // this jurisdiction's active board (which is also exactly what derives
        // R-08, so provenance passing implies the role gate passes). Calls the
        // real helpers, so the check can never drift from filing.
        $canDraw = false;
        if (($drawUser = $request->user()) !== null) {
            if (\App\Domain\Forms\Support\BoardProvenance::isFounderInSetupContext($drawUser, (string) $leg->jurisdiction_id)) {
                $canDraw = true;
            } else {
                try {
                    $drawBoard = \App\Domain\Forms\Support\BoardProvenance::boardForJurisdiction((string) $leg->jurisdiction_id, 'F-ELB-008');
                    \App\Domain\Forms\Support\BoardProvenance::resolveMemberOnBoard($drawUser, $drawBoard, 'F-ELB-008');
                    $canDraw = true;
                } catch (\App\Domain\Engine\ConstitutionalViolation) {
                    // no active board, or no own seat on it — the draw tools stay read-only
                }
            }
        }

        return Inertia::render('Legislature/Districts', [
            'surface' => \App\Support\SurfaceMeta::for('legislature/districts'),
            'legislature' => [
                'id'                   => $leg->id,
                'slug'                 => $legSlug,   // canonical = root jurisdiction slug
                'root_jurisdiction_id' => $leg->jurisdiction_id,
                'type_a_seats'         => (int) $leg->type_a_seats,
                'type_b_seats'         => (int) ($leg->type_b_seats ?? 0),
                'status'               => $leg->status,
                // FE-C2 — seated chamber: the mapper header gains a
                // "Chamber →" link to the legislature-home surface.
                'chamber_seated'       => DB::table('legislature_members')
                    ->where('legislature_id', $leg->id)
                    ->whereIn('status', ['elected', 'seated'])
                    ->whereNull('deleted_at')
                    ->exists(),
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
                    'slug'       => $scope->slug,
                    'adm_level'  => $scope->adm_level,
                    'population' => (int) $scope->population,
                    'bbox'       => $bboxRow
                        ? [(float) $bboxRow->south, (float) $bboxRow->west,
                           (float) $bboxRow->north, (float) $bboxRow->east]
                        : null,
                ];
            })(),
            'scope_seats' => $scopeSeats,   // rounded entitlement at this drill-down level
            'ancestors' => array_map(fn($a) => ['id' => $a->id, 'name' => $a->name, 'slug' => $a->slug], $ancestors),

            // Heavy props — inline. We previously wrapped these in Inertia::defer()
            // to speed up cold-restart rendering, but the v2 client's auto-fetch
            // for deferred props wasn't firing (verified empirically), leaving
            // the sidebar permanently empty. Cold-restart is now solved at the
            // container layer (OPcache pre-warm, healthcheck-gated nginx, larger
            // FPM pool), so the ~1s heavy work is paid up front and the data
            // always arrives in the initial render. The $loadHeavy closure stays
            // memoized so reading 5 keys runs the backend pass once.
            'children'  => $loadHeavy()['children'],
            'districts' => $loadHeavy()['districts'],
            'quota'     => $loadHeavy()['quota'],
            'flags'     => $loadHeavy()['flags'],
            'stats'     => $loadHeavy()['stats'],

            'mass_tool_running' => $massToolRunning,
            'can_draw'   => $canDraw,
            // WHETHER the displayed map may be drawn into (can_draw is WHO):
            // drafts always; the ACTIVE map only during the SETUP context —
            // the founding (v1) map is drawn directly because no standing
            // government exists yet to run draft → approval → vote (map v2+).
            // Shares the exact rule the F-ELB-008 handler enforces.
            'map_drawable' => $activeMapRow !== null && (
                $activeMapRow->status === 'draft'
                || ($activeMapRow->status === 'active'
                    && \App\Domain\Forms\Support\BoardProvenance::inSetupContext((string) $leg->jurisdiction_id))
            ),
            'maps'       => $allMaps,
            'active_map' => $activeMapRow ? [
                'id'     => $activeMapRow->id,
                'name'   => $activeMapRow->name,
                'status' => $activeMapRow->status,
            ] : null,
            // Constitutional thresholds — surfaced as Inertia props so the Vue
            // side (Show.vue) can substitute them for its previously hardcoded
            // GIANT_THRESHOLD = 9.5 etc. Lets operator-set floor/ceiling values
            // actually flow through into the district-mapper's giant
            // identification, composite validation, and floor-override flags.
            'constitutional' => [
                'floor'           => $floor,
                'ceiling'         => $ceiling,
                'giant_threshold' => $giantThreshold,
                'floor_boundary'  => $floorBoundary,
                'floor_override'  => $floorOverride,
            ],
            // Wizard integration: when the user arrives from /setup/step/3,
            // ?setup=1 toggles a sticky banner with a return-to-wizard button.
            // Whether an active map exists determines whether the banner
            // surfaces a "Back to Setup →" action or only the reminder text.
            'setup_mode' => (bool) $request->query('setup'),
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

        // Constitutional thresholds (substituted for the legacy 9.5/5/9 literals).
        ['giant' => $giantThreshold] = $this->thresholds($leg->jurisdiction_id);
        $floor   = ConstitutionalDefaults::floor($leg->jurisdiction_id);
        $ceiling = ConstitutionalDefaults::ceiling($leg->jurisdiction_id);

        // Compute local quota: scope children population / scope seat budget.
        // Seat budget via the gated computeSeatBudget cascade — Path 2
        // covers composite-member scopes (cheap indexed lookup), Path 3
        // covers giants (walks parent chain). Returns NULL only on a
        // degenerate (no legislature) case; fall back to a proportional
        // approximation then.
        $rootPop = max((int) DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('population'), 1);
        $scopeRow = DB::table('jurisdictions')->where('id', $scopeId)->whereNull('deleted_at')->first();
        $scopeChildrenPop = (int) DB::table('jurisdictions')
            ->where('parent_id', $scopeId)
            ->whereNull('deleted_at')
            ->sum('population');
        $seatBudget = $this->computeSeatBudget($scopeId, $legislature_id)
            ?? max($floor, (int) round((int) ($scopeRow ? $scopeRow->population : 0) * (int) $leg->type_a_seats / $rootPop));
        $localQuota = $scopeChildrenPop / max($seatBudget, 1);

        // Validate: no individual member may be a giant (frac >= giant threshold)
        // — giants cannot be composited.
        foreach ($jRows as $jRow) {
            $memberFrac = (int) $jRow->population / max($localQuota, 1);
            if ($memberFrac >= $giantThreshold) {
                return response()->json([
                    'error' => sprintf(
                        '%s has %.2f fractional seats (≥ %.1f). ' .
                        'Giant jurisdictions cannot be assigned to a district at this level — drill down instead.',
                        $jRow->name, $memberFrac, $giantThreshold
                    ),
                ], 422);
            }
        }

        // Compute effective floor + non-giant quota — mirrors runAutoCompositeForScope() logic.
        // When giants consume most of the budget, the remaining non-giant budget may be less
        // than the floor, so the floor is capped at that remainder.
        // Also derive ngLocalQuota (non-giant quota) so the stored fractional correctly
        // represents the district's share of the non-giant seat pool.
        //
        // This block runs BEFORE the composite-ceiling check below so that the
        // check can validate against the SAME non-giant fractional the district
        // is actually stored + displayed with — see the note on the check.
        $allScopeChildren = DB::table('jurisdictions')
            ->where('parent_id', $scopeId)
            ->whereNull('deleted_at')
            ->get(['id', 'population']);
        $giantSeatsCommitted = 0;
        $giantPopCommitted   = 0;
        foreach ($allScopeChildren as $child) {
            $childFrac = (int) $child->population / max($localQuota, 1);
            if ($childFrac >= $giantThreshold) {
                // Locked giant seat count via the gated cascade. Falls back
                // to Webster-rounded local frac if the cascade returns NULL
                // (degenerate case; same fallback as runAutoCompositeForScope).
                $childSeats = $this->computeSeatBudget($child->id, $legislature_id)
                    ?? max($floor, (int) round($childFrac));
                $giantSeatsCommitted += $childSeats;
                $giantPopCommitted   += (int) $child->population;
            }
        }
        $nonGiantBudget = max(1, $seatBudget - $giantSeatsCommitted);
        $effectiveFloor = min($floor, $nonGiantBudget);

        // Non-giant quota: guarantees SUM(non-giant fracs) = nonGiantBudget exactly.
        $ngLocalQuota = $giantSeatsCommitted > 0
            ? max($scopeChildrenPop - $giantPopCommitted, 1) / $nonGiantBudget
            : $localQuota;

        // The stored + displayed fractional uses the non-giant quota so it's
        // comparable to the sibling fracs shown in the sidebar.
        $totalPop   = (int) $jRows->sum('population');
        $fractional = $totalPop / max($ngLocalQuota, 1);

        // Validate: the composite must not round above the constitutional district
        // maximum. Checked against $fractional — the SAME non-giant value the
        // district is stored with and the sidebar shows — NOT the full $localQuota.
        // At scopes where giants lock up most of the budget the two quotas diverge,
        // and the old full-quota check rejected composites the sidebar reported as
        // valid (e.g. China: sidebar 7.29→7 seats, but full quota read 7.70→reject).
        // $giantThreshold = ceiling + 0.5, so $fractional ≥ it ⟺ round() > ceiling.
        if ($fractional >= $giantThreshold) {
            return response()->json([
                'error' => sprintf(
                    'Composite fractional seats (%.2f) ≥ %.1f — would round to > %d, ' .
                    'exceeding the constitutional district maximum of %d. Remove a jurisdiction.',
                    $fractional, $giantThreshold, $ceiling, $ceiling
                ),
            ], 422);
        }

        // Webster (Sainte-Laguë) rounding — clamp to [effectiveFloor, ceiling]
        $seats         = max($effectiveFloor, min($ceiling, (int) round($fractional)));
        $floorOverride = $seats < $floor;

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

            // Snapshot the current coloring BEFORE recomputeDistrict() below flushes
            // the color cache. A warm cache omits the just-inserted district (we patch
            // it in incrementally); a cold cache recomputes the full map including it.
            $colorMapBefore = $this->legislatureColorMap($legislature_id, $mapId);

            // Recompute geometry + seats for districts that lost members
            foreach ($existingDistrictIds as $affectedId) {
                $this->recomputeDistrict($affectedId, $legislature_id, $leg);
            }

            // Compute and cache spatial stats (polsby_popper, num_geom_parts) for the
            // new district.  createDistrict() sets seats/population inline above but
            // does not run recomputeDistrict() for the new record — call it here now
            // that all member junctions are inserted and the transaction is still open.
            $this->recomputeDistrict($districtId, $legislature_id, $leg);

            // Renumber so the sequence stays compact (new district gets MAX+1 above,
            // but renumbering here ensures any prior gaps are healed too)
            $this->renumberDistricts($legislature_id, $scopeId, $mapId);

            DB::commit();

            $newDistrict = DB::table('legislature_districts')->where('id', $districtId)->first();
            $districtNumber = (int) $newDistrict->district_number;  // refresh after renumber

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

            $this->flushRevealedCache($legislature_id, $mapId, $scopeId);

            // Incremental coloring (see colorForDistrict): the new district takes
            // the smallest color its cross-scope neighbors don't use; every existing
            // district keeps its color. Replaces the legislature-wide recolor that
            // ran a bbox self-join over every member on each create. $colorMapBefore
            // was snapshotted before recomputeDistrict() flushed the cache: a warm
            // cache omits the new district (patch it in); a cold cache already
            // recomputed the full map including it (skip the patch). Re-prime so the
            // cache stays warm for the next draw instead of forcing a recompute.
            $scopeColors = $colorMapBefore;
            if (!array_key_exists($districtId, $scopeColors)) {
                $scopeColors[$districtId] = $this->colorForDistrict($districtId, $legislature_id, $mapId, $scopeColors);
            }
            $this->primeColorCache($legislature_id, $mapId, $scopeColors);

            // Districts that lost members had their seats recomputed via recomputeDistrict() above.
            $affectedDistrictsData = [];
            foreach ($existingDistrictIds as $did) {
                $affRow = DB::table('legislature_districts')->where('id', $did)->whereNull('deleted_at')->first();
                if ($affRow) {
                    $affectedDistrictsData[] = [
                        'id'             => $did,
                        'seats'          => (int) $affRow->seats,
                        'floor_override' => (bool) $affRow->floor_override,
                        'color_index'    => $scopeColors[$did] ?? 0,
                    ];
                }
            }

            return response()->json([
                'district' => [
                    'id'               => $newDistrict->id,
                    'seats'            => (int) $newDistrict->seats,
                    'floor_override'   => (bool) $newDistrict->floor_override,
                    'fractional_seats' => round($fractional, 4),
                    'color_index'      => $scopeColors[$newDistrict->id] ?? 0,
                    'status'           => $newDistrict->status,
                    'member_count'     => count($jids),
                    'district_number'  => $districtNumber,
                    'name'             => $districtName,
                    'convex_hull_ratio' => $newDistrict->convex_hull_ratio !== null ? round((float) $newDistrict->convex_hull_ratio, 3) : null,
                    'is_contiguous'     => $newDistrict->is_contiguous !== null ? (bool) $newDistrict->is_contiguous : null,
                    'has_integrity'     => true,  // always true — composited from existing admin children
                ],
                'affected_districts' => $affectedDistrictsData,
                'color_indices'      => $scopeColors,
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

        // Constitutional thresholds (substituted for the legacy 9.5/5/9 literals).
        ['giant' => $giantThreshold] = $this->thresholds($leg->jurisdiction_id);
        $floor   = ConstitutionalDefaults::floor($leg->jurisdiction_id);
        $ceiling = ConstitutionalDefaults::ceiling($leg->jurisdiction_id);

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
            // Seat budget via the gated cascade. Falls back to proportional
            // approximation in the degenerate case (no legislature row).
            $distSeatBudget = $this->computeSeatBudget($distScopeId, $legislature_id)
                ?? max($floor, (int) round((int) ($distScopeRow ? $distScopeRow->population : 0) * (int) $leg->type_a_seats / $distRootPop));
            $localQuota = $distScopePop / max($distSeatBudget, 1);

            // Validate no added jurisdiction is a giant
            $addRows = DB::table('jurisdictions')->whereIn('id', $add)->get(['id', 'name', 'population']);
            foreach ($addRows as $aRow) {
                $frac = (int) $aRow->population / max($localQuota, 1);
                if ($frac >= $giantThreshold) {
                    return response()->json([
                        'error' => "{$aRow->name} has " . number_format($frac, 2) . " fractional seats (≥ " . number_format($giantThreshold, 1) . "). " .
                                   "Giant jurisdictions cannot be composited — drill down instead.",
                    ], 422);
                }
            }

            // Non-giant quota (mirrors createDistrict / runAutoCompositeForScope):
            // giants lock their seats first, so the composite's stored + displayed
            // fractional is taken against the non-giant pool. Validate the ceiling
            // with THIS quota — not the full $localQuota — so the error stays
            // consistent with the sidebar at giant-heavy scopes (e.g. China).
            $ngGiantSeats = 0;
            $ngGiantPop   = 0;
            foreach (DB::table('jurisdictions')->where('parent_id', $distScopeId)->whereNull('deleted_at')->get(['id', 'population']) as $child) {
                $childFrac = (int) $child->population / max($localQuota, 1);
                if ($childFrac >= $giantThreshold) {
                    $ngGiantSeats += $this->computeSeatBudget($child->id, $legislature_id) ?? max($floor, (int) round($childFrac));
                    $ngGiantPop   += (int) $child->population;
                }
            }
            $ngLocalQuota = $ngGiantSeats > 0
                ? max($distScopePop - $ngGiantPop, 1) / max(1, $distSeatBudget - $ngGiantSeats)
                : $localQuota;

            // Compute post-edit total fractional against the non-giant quota.
            $existingPop = (int) DB::table('legislature_district_jurisdictions as ldj')
                ->join('jurisdictions as j', 'j.id', '=', 'ldj.jurisdiction_id')
                ->where('ldj.district_id', $district_id)
                ->whereNotIn('ldj.jurisdiction_id', $remove)
                ->sum('j.population');
            $addPop      = (int) $addRows->sum('population');
            $projectedFrac = ($existingPop + $addPop) / max($ngLocalQuota, 1);

            if ($projectedFrac >= $giantThreshold) {
                return response()->json([
                    'error' => sprintf(
                        'Projected composite fractional seats (%.2f) ≥ %.1f — would round to > %d, ' .
                        'exceeding the constitutional district maximum of %d.',
                        $projectedFrac, $giantThreshold, $ceiling, $ceiling
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

            // Snapshot the coloring BEFORE recomputeDistrict() flushes the color
            // cache, so we can patch incrementally (warm) or reuse the cold
            // recompute, then re-prime — instead of a legislature-wide recolor.
            $resolvedMapId  = $this->getMapId($legislature_id, $district->map_id);
            $colorMapBefore = $this->legislatureColorMap($legislature_id, $resolvedMapId);

            // Recompute this district + affected source districts
            $this->recomputeDistrict($district_id, $legislature_id, $leg);
            foreach (array_unique($affectedDistrictIds) as $affectedId) {
                $this->recomputeDistrict($affectedId, $legislature_id, $leg);
            }

            DB::commit();

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

            $this->flushRevealedCache($legislature_id, $resolvedMapId, $district->jurisdiction_id);

            // Incremental coloring: only the district that GAINED members (add) can
            // pick up a new cross-scope neighbor, so re-pick just its color. Source
            // districts only LOST members (adjacency shrank — no new collision) and a
            // remove-only edit needs no recolor at all; every other district keeps its
            // color. Re-prime so the cache stays warm for the next draw.
            $scopeColors = $colorMapBefore;
            if (!empty($add)) {
                $scopeColors[$district_id] = $this->colorForDistrict($district_id, $legislature_id, $resolvedMapId, $scopeColors);
            }
            $this->primeColorCache($legislature_id, $resolvedMapId, $scopeColors);

            $affectedDistrictsData = [];
            foreach (array_unique($affectedDistrictIds) as $did) {
                $affRow = DB::table('legislature_districts')->where('id', $did)->whereNull('deleted_at')->first();
                if ($affRow) {
                    $affectedDistrictsData[] = [
                        'id'             => $did,
                        'seats'          => (int) $affRow->seats,
                        'floor_override' => (bool) $affRow->floor_override,
                        'color_index'    => $scopeColors[$did] ?? 0,
                    ];
                }
            }

            return response()->json([
                'district' => [
                    'id'               => $updated->id,
                    'seats'            => (int) $updated->seats,
                    'floor_override'   => (bool) $updated->floor_override,
                    'fractional_seats' => round((float) $updated->fractional_seats, 4),
                    'color_index'      => $scopeColors[$updated->id] ?? 0,
                    'status'           => $updated->status,
                    'member_count'     => $memberCount,
                    'district_number'  => (int) $updated->district_number,
                    'name'             => $districtName,
                    'convex_hull_ratio' => $updated->convex_hull_ratio !== null ? round((float) $updated->convex_hull_ratio, 3) : null,
                    'is_contiguous'     => $updated->is_contiguous !== null ? (bool) $updated->is_contiguous : null,
                    'has_integrity'     => true,  // always true — composited from existing admin children
                ],
                'affected_districts' => $affectedDistrictsData,
                'color_indices'      => $scopeColors,
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

        // A DRAWN district (leaf-giant subdivision) carries its geometry on a
        // district_subdivisions row reached through a subdivision_id membership.
        // Retire that row WITH the district: leaving it live is the "ghost"
        // that blocked every later draw at the scope — its label collided with
        // the next auto-numbered filing (23505) and its geometry tripped the
        // sibling-overlap gate. Composite districts have no subdivision rows,
        // so this is a no-op for them.
        $subdivisionIds = DB::table('legislature_district_jurisdictions')
            ->where('district_id', $district_id)
            ->whereNotNull('subdivision_id')
            ->pluck('subdivision_id');
        if ($subdivisionIds->isNotEmpty()) {
            DB::table('district_subdivisions')
                ->whereIn('id', $subdivisionIds)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
        }

        DB::table('legislature_district_jurisdictions')->where('district_id', $district_id)->delete();
        DB::table('legislature_districts')->where('id', $district_id)->update(['deleted_at' => now()]);

        // Renumber remaining districts so the sequence stays compact (no gaps from soft deletes)
        if ($scopeId) {
            $this->renumberDistricts($legislature_id, $scopeId, $distMapId);
        }

        $this->flushRevealedCache($legislature_id, $distMapId, $scopeId);

        // Return updated district_numbers AND new color_indices so frontend
        // can sync both without a full page reload. Removing a district
        // changes the scope's adjacency graph and may shift any sibling's
        // color (greedy 7-coloring re-runs over the surviving set).
        $remaining = DB::table('legislature_districts')
            ->where('legislature_id', $legislature_id)
            ->where('jurisdiction_id', $scopeId)
            ->whereNull('deleted_at')
            ->when($distMapId, fn($q) => $q->where('map_id', $distMapId))
            ->select('id', 'district_number')
            ->get();

        // Removing a district can only FREE colors for its neighbors (adjacency
        // shrinks), never create a collision — so every surviving district keeps
        // its color. Just drop the deleted district's entry and re-prime.
        // (deleteDistrict doesn't call recomputeDistrict, so the color cache wasn't
        // flushed; this stays a cache read + patch. A cold cache recomputes once
        // over the surviving set.)
        $colorIndices = $this->legislatureColorMap($legislature_id, $distMapId);
        unset($colorIndices[$district_id]);
        $this->primeColorCache($legislature_id, $distMapId, $colorIndices);
        $districtNumbers = [];
        foreach ($remaining as $r) {
            $districtNumbers[$r->id] = (int) $r->district_number;
        }

        return response()->json([
            'success'          => true,
            'district_numbers' => $districtNumbers,
            'color_indices'    => $colorIndices,
        ]);
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
        $revMapFil3 = $revMapId !== null ? 'AND ld.map_id = :map_id3' : '';
        $revMapFil4 = $revMapId !== null ? 'AND ld.map_id = :map_id4' : '';
        $revMapFil5 = $revMapId !== null ? 'AND ld.map_id = :map_id5' : '';
        $revMapBind = $revMapId !== null
            ? ['map_id' => $revMapId, 'map_id2' => $revMapId, 'map_id3' => $revMapId,
               'map_id4' => $revMapId, 'map_id5' => $revMapId]
            : [];

        // Root pop + total seats — used to enforce the giant threshold
        // (>= constitutional giant_threshold frac seats) on both depth branches
        // so non-giant scope districts never appear in the revealed layer.
        $legJid = (string) DB::table('legislatures')
            ->where('id', $legislature_id)
            ->whereNull('deleted_at')
            ->value('jurisdiction_id');
        $giantThreshold = ConstitutionalDefaults::giantThreshold($legJid);
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
        //
        // Persist-until-invalidated: rememberForever (not a 24h TTL) so prewarmed
        // Earth/giant payloads stay hot until a district edit flushes their tag
        // (flushRevealedCache) or a giants-reconfig / ETL flush fires. The heavy
        // cold build (~90s at Earth scope) is then paid once, by the prewarm job.
        $payload = Cache::tags([$cacheTag, "revealed.{$legislature_id}"])->rememberForever($cacheKey, function () use (
            $legislature_id, $scopeId, $revMapId, $revMapFilt, $revMapFil2, $revMapFil3, $revMapFil4, $revMapFil5, $revMapBind,
            $revRootPop, $revTotalSeats, $giantThreshold, $tol
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
        // color_index is filled in below via colorIndicesForDistricts() — the
        // adjacency graph is built from the resulting district set.
        $rows = DB::select("
            -- Branch 1: ADM2-level members whose direct parent is a giant Earth-child
            SELECT
                ld.id            AS district_id,
                ld.seats,
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
                ST_AsGeoJSON(ST_Simplify(j_member.geom, {$tol})) AS geojson,
                j_dscope.population        AS scope_pop,
                COALESCE(scc.cnt, 0)       AS scope_child_count
            FROM legislature_districts ld
            JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
            JOIN jurisdictions j_member ON j_member.id = ldj.jurisdiction_id
                AND j_member.deleted_at IS NULL
                AND j_member.geom IS NOT NULL
            JOIN jurisdictions j_giant ON j_giant.id = j_member.parent_id
                AND j_giant.parent_id = :scope_id
                AND j_giant.deleted_at IS NULL
                AND (CAST(j_giant.population AS numeric) * :total_seats / :root_pop) >= :giant_threshold
            LEFT JOIN jurisdictions j_gp ON j_gp.id = j_giant.parent_id
            -- scope_pop / scope_child_count are properties of the district's own
            -- scope jurisdiction (ld.jurisdiction_id). Computed via set-based joins
            -- (PK join + a single LATERAL count) instead of two correlated subqueries
            -- in the projection, which previously re-executed once PER MEMBER ROW
            -- (~3.5k rows at Earth scope = ~7k extra index lookups on a cold cache).
            LEFT JOIN jurisdictions j_dscope ON j_dscope.id = ld.jurisdiction_id
                AND j_dscope.deleted_at IS NULL
            LEFT JOIN LATERAL (
                SELECT COUNT(*) AS cnt FROM jurisdictions jcc
                WHERE jcc.parent_id = ld.jurisdiction_id AND jcc.deleted_at IS NULL
            ) scc ON true
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
                ST_AsGeoJSON(ST_Simplify(j_member.geom, {$tol})),
                j_dscope2.population,
                COALESCE(scc2.cnt, 0)
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
                AND (CAST(j_giant.population AS numeric) * :total_seats2 / :root_pop2) >= :giant_threshold2
            -- Same set-based rewrite as Branch 1 (see note above).
            LEFT JOIN jurisdictions j_dscope2 ON j_dscope2.id = ld.jurisdiction_id
                AND j_dscope2.deleted_at IS NULL
            LEFT JOIN LATERAL (
                SELECT COUNT(*) AS cnt FROM jurisdictions jcc2
                WHERE jcc2.parent_id = ld.jurisdiction_id AND jcc2.deleted_at IS NULL
            ) scc2 ON true
            WHERE ld.legislature_id = :leg_id2
              AND ld.deleted_at IS NULL
              {$revMapFil2}

            UNION ALL

            -- Branch 3 (Phase H): drawn/split DISTRICT_SUBDIVISIONS of a CHILDLESS
            -- LEAF GIANT. These render only when the scope IS the giant itself
            -- (ld.jurisdiction_id = scope) — drilling into Serravalle shows its
            -- hand-drawn pieces, each carried by a polymorphic subdivision_id
            -- membership rather than a whole jurisdiction. The piece's own geometry
            -- is authoritative (composites derive geometry from members; a drawn
            -- piece IS the geometry). scope_pop = the piece's population so the
            -- has_integrity check reads it as an in-band sub-district, not a giant.
            SELECT
                ld.id,
                ld.seats,
                ld.floor_override,
                j_giant.id,
                j_giant.name,
                ld.district_number,
                j_giant.iso_code,
                j_giant.adm_level,
                j_gp3.iso_code,
                j_gp3.adm_level,
                j_gp3.name,
                (j_gp3.parent_id IS NULL),
                j_giant.id,
                ld.actual_population,
                ld.fractional_seats,
                ld.convex_hull_ratio,
                ld.is_contiguous,
                ds.id,
                ds.label,
                j_giant.iso_code,
                (j_giant.adm_level + 1),
                ST_AsGeoJSON(ST_Simplify(ds.geom, {$tol})),
                ds.population,
                0
            FROM legislature_districts ld
            JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
                AND ldj.subdivision_id IS NOT NULL
            JOIN district_subdivisions ds ON ds.id = ldj.subdivision_id
                AND ds.deleted_at IS NULL
                AND ds.geom IS NOT NULL
            JOIN jurisdictions j_giant ON j_giant.id = ld.jurisdiction_id
                AND j_giant.deleted_at IS NULL
            LEFT JOIN jurisdictions j_gp3 ON j_gp3.id = j_giant.parent_id
            WHERE ld.legislature_id = :leg_id3
              AND ld.deleted_at IS NULL
              AND ld.jurisdiction_id = :scope_id3
              {$revMapFil3}

            UNION ALL

            -- Branch 4 (Phase 5e): drawn subdivisions of a childless leaf giant
            -- ONE level below the scope — the ANCESTOR view of Branch 3. A
            -- drawn district's membership carries only a subdivision_id (no
            -- jurisdiction rows), so Branch 1/2's member→giant ancestry walk
            -- can never surface it; this branch reaches it from the giant side
            -- (ld.jurisdiction_id IS the giant) with the same giant-threshold
            -- gate Branch 1 applies, and emits the exact Branch 3 prop shape
            -- so tooltips read identically at every scope.
            SELECT
                ld.id,
                ld.seats,
                ld.floor_override,
                j_giant.id,
                j_giant.name,
                ld.district_number,
                j_giant.iso_code,
                j_giant.adm_level,
                j_gp4.iso_code,
                j_gp4.adm_level,
                j_gp4.name,
                (j_gp4.parent_id IS NULL),
                j_giant.id,
                ld.actual_population,
                ld.fractional_seats,
                ld.convex_hull_ratio,
                ld.is_contiguous,
                ds.id,
                ds.label,
                j_giant.iso_code,
                (j_giant.adm_level + 1),
                ST_AsGeoJSON(ST_Simplify(ds.geom, {$tol})),
                ds.population,
                0
            FROM legislature_districts ld
            JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
                AND ldj.subdivision_id IS NOT NULL
            JOIN district_subdivisions ds ON ds.id = ldj.subdivision_id
                AND ds.deleted_at IS NULL
                AND ds.geom IS NOT NULL
            JOIN jurisdictions j_giant ON j_giant.id = ld.jurisdiction_id
                AND j_giant.parent_id = :scope_id4
                AND j_giant.deleted_at IS NULL
                AND (CAST(j_giant.population AS numeric) * :total_seats4 / :root_pop4) >= :giant_threshold4
            LEFT JOIN jurisdictions j_gp4 ON j_gp4.id = j_giant.parent_id
            WHERE ld.legislature_id = :leg_id4
              AND ld.deleted_at IS NULL
              {$revMapFil4}

            UNION ALL

            -- Branch 5: same as Branch 4 for a giant TWO levels below the scope
            -- — parity with Branch 2's deeper ancestry (scope → mid → giant).
            SELECT
                ld.id,
                ld.seats,
                ld.floor_override,
                j_giant.id,
                j_giant.name,
                ld.district_number,
                j_giant.iso_code,
                j_giant.adm_level,
                j_gp5.iso_code,
                j_gp5.adm_level,
                j_gp5.name,
                (j_gp5.parent_id IS NULL),
                j_giant.id,
                ld.actual_population,
                ld.fractional_seats,
                ld.convex_hull_ratio,
                ld.is_contiguous,
                ds.id,
                ds.label,
                j_giant.iso_code,
                (j_giant.adm_level + 1),
                ST_AsGeoJSON(ST_Simplify(ds.geom, {$tol})),
                ds.population,
                0
            FROM legislature_districts ld
            JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
                AND ldj.subdivision_id IS NOT NULL
            JOIN district_subdivisions ds ON ds.id = ldj.subdivision_id
                AND ds.deleted_at IS NULL
                AND ds.geom IS NOT NULL
            JOIN jurisdictions j_giant ON j_giant.id = ld.jurisdiction_id
                AND j_giant.deleted_at IS NULL
                AND (CAST(j_giant.population AS numeric) * :total_seats5 / :root_pop5) >= :giant_threshold5
            JOIN jurisdictions j_mid5 ON j_mid5.id = j_giant.parent_id
                AND j_mid5.parent_id = :scope_id5
                AND j_mid5.deleted_at IS NULL
            LEFT JOIN jurisdictions j_gp5 ON j_gp5.id = j_giant.parent_id
            WHERE ld.legislature_id = :leg_id5
              AND ld.deleted_at IS NULL
              {$revMapFil5}
        ", array_merge([
            'leg_id'           => $legislature_id,
            'scope_id'         => $scopeId,
            'total_seats'      => $revTotalSeats,
            'root_pop'         => $revRootPop,
            'giant_threshold'  => $giantThreshold,
            'leg_id2'          => $legislature_id,
            'scope_id2'        => $scopeId,
            'total_seats2'     => $revTotalSeats,
            'root_pop2'        => $revRootPop,
            'giant_threshold2' => $giantThreshold,
            'leg_id3'          => $legislature_id,
            'scope_id3'        => $scopeId,
            'leg_id4'          => $legislature_id,
            'scope_id4'        => $scopeId,
            'total_seats4'     => $revTotalSeats,
            'root_pop4'        => $revRootPop,
            'giant_threshold4' => $giantThreshold,
            'leg_id5'          => $legislature_id,
            'scope_id5'        => $scopeId,
            'total_seats5'     => $revTotalSeats,
            'root_pop5'        => $revRootPop,
            'giant_threshold5' => $giantThreshold,
        ], $revMapBind));

        // Adjacency-aware greedy 7-coloring over the WHOLE legislature/map.
        // Using the legislature-wide map (not just this response's districts)
        // matters because non-giant country districts (rendered via
        // /api/jurisdictions/{id}/children.geojson + show()'s districts prop)
        // are NOT in this response's set — but they ARE visually adjacent to
        // the Phase-4 sub-districts of their giant neighbors. Sharing the
        // coloring eliminates the cross-set collisions.
        $colorMap = $this->legislatureColorMap($legislature_id, $revMapId);

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
                    'color_index'            => $colorMap[$row->district_id] ?? 0,
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
                    'has_integrity'             => !(($revTotalSeats > 0 && $revRootPop > 0 ? ((float) ($row->scope_pop ?? 0)) * $revTotalSeats / $revRootPop : 0) >= $giantThreshold && (int) ($row->scope_child_count ?? 0) === 0),
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
                AND (CAST(j_giant.population AS numeric) * :total_seats_o / :root_pop_o) >= :giant_threshold_o
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
                AND (CAST(j_giant.population AS numeric) * :total_seats_o2 / :root_pop_o2) >= :giant_threshold_o2
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
                AND (CAST(j_giant.population AS numeric) * :total_seats_o3 / :root_pop_o3) >= :giant_threshold_o3
            WHERE ld.legislature_id = :leg_id_o3
              AND ld.deleted_at IS NULL

            UNION

            -- Branch D (Phase 5e): depth-1 outlines for childless leaf giants
            -- revealed through DRAWN subdivisions (rows Branch 4 paints) —
            -- their memberships carry no jurisdictions, so A/B never find them.
            SELECT DISTINCT
                j_giant.id,
                j_giant.name,
                ST_AsGeoJSON(ST_Simplify(j_giant.geom, {$tol})),
                1
            FROM legislature_districts ld
            JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
                AND ldj.subdivision_id IS NOT NULL
            JOIN jurisdictions j_giant ON j_giant.id = ld.jurisdiction_id
                AND j_giant.parent_id = :scope_id_o4
                AND j_giant.deleted_at IS NULL
                AND j_giant.geom IS NOT NULL
                AND (CAST(j_giant.population AS numeric) * :total_seats_o4 / :root_pop_o4) >= :giant_threshold_o4
            WHERE ld.legislature_id = :leg_id_o4
              AND ld.deleted_at IS NULL
        ", [
            'leg_id_o'           => $legislature_id,
            'scope_id_o'         => $scopeId,
            'total_seats_o'      => $revTotalSeats,
            'root_pop_o'         => $revRootPop,
            'giant_threshold_o'  => $giantThreshold,
            'leg_id_o2'          => $legislature_id,
            'scope_id_o2'        => $scopeId,
            'total_seats_o2'     => $revTotalSeats,
            'root_pop_o2'        => $revRootPop,
            'giant_threshold_o2' => $giantThreshold,
            'leg_id_o3'          => $legislature_id,
            'scope_id_o3'        => $scopeId,
            'total_seats_o3'     => $revTotalSeats,
            'root_pop_o3'        => $revRootPop,
            'giant_threshold_o3' => $giantThreshold,
            'leg_id_o4'          => $legislature_id,
            'scope_id_o4'        => $scopeId,
            'total_seats_o4'     => $revTotalSeats,
            'root_pop_o4'        => $revRootPop,
            'giant_threshold_o4' => $giantThreshold,
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
        // ensureMapId auto-creates a "Draft N" map if none exists so the
        // districts inserted here belong to a versioned plan instead of
        // floating with map_id = NULL.
        $mapId         = $this->ensureMapId($legislature_id, $request->input('map_id'));

        if (!$scopeId) {
            return response()->json(['error' => 'scope_id is required'], 422);
        }

        // Publish a single-scope progress marker so the wizard's stepper-driven
        // autoseed (which calls this endpoint one scope at a time) gets the
        // same fine-grained phase visibility as massReseed's sweep.
        $scopeName = DB::table('jurisdictions')
            ->where('id', $scopeId)
            ->value('name') ?? $scopeId;
        Cache::put("legislature.{$legislature_id}.mass_running", true, 1800);
        $this->publishMassProgress($legislature_id, [
            'current_scope'    => $scopeName,
            'current_scope_id' => $scopeId,
            'completed'        => 0,
            'total'            => 1,
            'started_at'       => time(),
            'scope_started_at' => time(),
            'phase'            => 'starting',
            'phase_label'      => "Starting autoseed for {$scopeName}",
            'phase_current'    => 0,
            'phase_total'      => 0,
        ], reset: true);

        // At root scope: auto-update type_a_seats from cube root of sum(children populations).
        // At non-root scope: derive seat budget via the gated computeSeatBudget
        // cascade (Path 2 lookup for composites, Path 3 recursion for giants).
        $isAutoRoot = ($scopeId === $leg->jurisdiction_id);
        DB::beginTransaction();
        try {
            if ($isAutoRoot) {
                $sumChildPop = (int) DB::table('jurisdictions')
                    ->where('parent_id', $scopeId)
                    ->whereNull('deleted_at')
                    ->sum('population');
                $newSeats = ConstitutionalDefaults::sizeFromPopulation($sumChildPop, $leg->jurisdiction_id);
                if ((int) $leg->type_a_seats !== $newSeats) {
                    DB::table('legislatures')->where('id', $legislature_id)->update(['type_a_seats' => $newSeats]);
                    $leg->type_a_seats = $newSeats;
                }
                $seatBudget = (int) $leg->type_a_seats;
            } else {
                $autoScope   = DB::table('jurisdictions')->where('id', $scopeId)->whereNull('deleted_at')->first();
                $autoRootPop = max((int) DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('population'), 1);
                // Gated cascade — Path 2 for composite scopes, Path 3 for
                // giants. Falls back to proportional approx only in
                // degenerate cases.
                $seatBudget = $this->computeSeatBudget($scopeId, $legislature_id)
                    ?? max(ConstitutionalDefaults::floor($leg->jurisdiction_id), (int) round((int) ($autoScope ? $autoScope->population : 0) * (int) $leg->type_a_seats / $autoRootPop));
            }

            $result = $this->runAutoCompositeForScope(
                $legislature_id, $leg, $scopeId, $clearExisting, $seatBudget, $mapId
            );
            if ($result['error'] !== null) {
                DB::rollBack();
                Cache::forget("legislature.{$legislature_id}.mass_running");
                Cache::forget("legislature.{$legislature_id}.mass_progress");
                return response()->json(['error' => $result['error']], 422);
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Cache::forget("legislature.{$legislature_id}.mass_running");
            Cache::forget("legislature.{$legislature_id}.mass_progress");
            return response()->json(['error' => 'Auto-composite failed: ' . $e->getMessage()], 500);
        }

        $this->publishMassProgress($legislature_id, [
            'completed'    => 1,
            'phase'        => 'done',
            'phase_label'  => "Autoseed complete: {$result['districts_created']} districts created",
        ]);

        // Invalidate revealed.geojson cache — autoComposite creates/replaces districts.
        // Colors are computed on the fly from district_number + scope via the
        // SQL expression in revealedGeoJson; nothing to recompute.
        $this->flushRevealedCache($legislature_id, $mapId, $scopeId);

        Cache::forget("legislature.{$legislature_id}.mass_running");
        Cache::forget("legislature.{$legislature_id}.mass_progress");

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

        if (!$operationScope || !$scopeId) {
            return response()->json(['error' => 'operation_scope and scope_id are required'], 422);
        }

        // Refuse to start a new sweep if one is already in flight. The Halt
        // button must be used to stop the existing run before launching another.
        if (Cache::get("legislature.{$legislature_id}.mass_running")) {
            return response()->json([
                'error' => 'A mass operation is already running. Wait for it to finish or click Halt to stop it.',
            ], 409);
        }

        // Auto-create a "Draft N" map if none exists so districts belong to a
        // versioned plan instead of floating with map_id = NULL.
        $mapId = $this->ensureMapId($legislature_id, $request->input('map_id'));

        // Clear any stale halt flag from a previous run before dispatching.
        Cache::forget("legislature.{$legislature_id}.mass_halt");

        // Mark this legislature as having an active mass operation. The job's
        // handle() and failed() callbacks clear this when it terminates so
        // the UI can re-enable controls.
        Cache::put("legislature.{$legislature_id}.mass_running", true, 7200);

        // Seed initial progress so the polling UI has something to show
        // before the job's worker picks it up. `reset=true` wipes stale
        // fields (current_scope, phase_total, scope_started_at) from any
        // previous run so the banner doesn't display "Queued — waiting
        // for worker" paired with "5m on scope" leftover from earlier.
        $this->publishMassProgress($legislature_id, [
            'completed'        => 0,
            'total'            => 0,
            'started_at'       => time(),
            'current_scope'    => null,
            'current_scope_id' => null,
            'scope_started_at' => null,
            'phase_current'    => 0,
            'phase_total'      => 0,
            'phase'            => 'queued',
            'phase_label'      => 'Queued — waiting for worker',
        ], reset: true);

        // Dispatch to Horizon. The job's timeout is 7200 s (2 h) which
        // covers a whole-Earth recursive sweep; per-scope commits inside
        // the job mean partial progress survives any worker death.
        \App\Jobs\MassReseedJob::dispatch(
            $legislature_id,
            $operationScope,
            $scopeId,
            $mapId,
        );

        return response()->json([
            'success'   => true,
            'dispatched'=> true,
            'map_id'    => $mapId,
        ], 202);
    }

    /**
     * Core mass-reseed sweep. Extracted from the massReseed endpoint so that
     * MassReseedJob (running in Horizon) can call it without a Request and
     * without holding a php-fpm worker for the duration.
     *
     * Each scope commits in its own transaction so partial progress survives
     * any error or timeout. Between scopes the job polls a halt flag in
     * cache (`legislature.{id}.mass_halt`) — if set, the sweep aborts
     * cleanly with `halted => true`.
     *
     * @return array{districts_created:int, scopes_processed:int, errors:array<int,string>, halted:bool, scope_ids:array<int,string>}
     */
    public function executeMassReseedSweep(
        string $legislature_id,
        string $operationScope,
        string $scopeId,
        string $mapId,
    ): array {
        // Record this worker's Postgres backend PID so massHalt() can cancel
        // / terminate its in-flight queries directly (instead of just setting
        // a flag the worker checks between scopes). Without this, halts of
        // single-scope ops or halts that land mid-PostGIS-query are useless —
        // the worker is stuck inside a C function that doesn't check the
        // PHP-level flag for tens of minutes.
        try {
            $pid = (int) (DB::selectOne("SELECT pg_backend_pid() AS pid")->pid ?? 0);
            if ($pid > 0) {
                Cache::put("legislature.{$legislature_id}.mass_db_pid", $pid, 7200);
            }
        } catch (\Throwable $e) {
            // Non-fatal — halts will just fall back to between-scope polling.
        }

        $leg = DB::table('legislatures')
            ->where('id', $legislature_id)
            ->whereNull('deleted_at')
            ->first();
        if (!$leg) {
            throw new \RuntimeException("Legislature {$legislature_id} not found");
        }

        $clearExisting = str_ends_with($operationScope, '_all');
        $rootPop = max((int) DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('population'), 1);

        // At root scope: auto-update type_a_seats from cube root of children sum.
        if ($scopeId === $leg->jurisdiction_id) {
            $sumChildPop = (int) DB::table('jurisdictions')
                ->where('parent_id', $scopeId)
                ->whereNull('deleted_at')
                ->sum('population');
            $newSeats = ConstitutionalDefaults::sizeFromPopulation($sumChildPop, $leg->jurisdiction_id);
            if ((int) $leg->type_a_seats !== $newSeats) {
                DB::table('legislatures')->where('id', $legislature_id)->update(['type_a_seats' => $newSeats]);
                $leg->type_a_seats = $newSeats;
            }
        }

        $rootQuota = $rootPop / max((int) $leg->type_a_seats, 1);

        $scopeIds = $this->resolveMassScopeIds(
            $legislature_id, $leg, $scopeId, $operationScope, $rootQuota, $mapId
        );

        $totalCreated    = 0;
        $scopesProcessed = 0;
        $errors          = [];
        $halted          = false;

        // Pre-fetch scope names for real-time progress display
        $scopeNames  = DB::table('jurisdictions')
            ->whereIn('id', $scopeIds)
            ->pluck('name', 'id')
            ->toArray();
        $totalScopes = count($scopeIds);
        $runStartedAt = time();

        $this->publishMassProgress($legislature_id, [
            'completed'     => 0,
            'total'         => $totalScopes,
            'started_at'    => $runStartedAt,
            'current_scope' => $scopeNames[$scopeIds[0] ?? null] ?? null,
            'phase'         => 'starting',
            'phase_label'   => 'Starting mass-reseed sweep',
        ]);

        // Per-scope transactions: each scope commits independently so partial
        // progress survives any error in subsequent scopes.
        foreach ($scopeIds as $scopeIdx => $sid) {
            // Halt poll — checked between scopes so a halt request takes effect
            // on the next scope boundary instead of mid-tx.
            if (Cache::get("legislature.{$legislature_id}.mass_halt")) {
                $halted = true;
                $this->publishMassProgress($legislature_id, [
                    'phase'       => 'halted',
                    'phase_label' => "Halted by operator after {$scopesProcessed}/{$totalScopes} scopes",
                    'completed'   => $scopeIdx,
                ]);
                break;
            }

            $scopeStart = time();
            $this->publishMassProgress($legislature_id, [
                'current_scope'    => $scopeNames[$sid] ?? $sid,
                'current_scope_id' => $sid,
                'completed'        => $scopeIdx,
                'total'            => $totalScopes,
                'phase'            => 'scope_start',
                'phase_label'      => "Starting scope: " . ($scopeNames[$sid] ?? $sid),
                'phase_current'    => 0,
                'phase_total'      => 0,
                'scope_started_at' => $scopeStart,
            ]);

            // Compute per-scope seat budget
            if ($sid === $leg->jurisdiction_id) {
                $seatBudget = (int) $leg->type_a_seats;
            } else {
                $sidScope    = DB::table('jurisdictions')->where('id', $sid)->whereNull('deleted_at')->first();
                $sidChildPop = (int) DB::table('jurisdictions')
                    ->where('parent_id', $sid)
                    ->whereNull('deleted_at')
                    ->sum('population');
                // Gated cascade — replaces the flat root-quota fallback
                // (which was the source of the +13 overcount: it used
                // sum-of-children pop × root quota for giants, producing
                // 32 instead of 29 for Guangzhou).
                $seatBudget = $this->computeSeatBudget($sid, $legislature_id)
                    ?? max(ConstitutionalDefaults::floor($leg->jurisdiction_id), (int) round(($sidScope ? (int) $sidScope->population : $sidChildPop) * (int) $leg->type_a_seats / $rootPop));
            }

            DB::beginTransaction();
            try {
                $result = $this->runAutoCompositeForScope(
                    $legislature_id, $leg, $sid, $clearExisting, $seatBudget, $mapId
                );
                if ($result['error'] !== null) {
                    DB::commit();
                    $errors[] = ($scopeNames[$sid] ?? $sid) . ": " . $result['error'];
                } else {
                    DB::commit();
                    $totalCreated    += $result['districts_created'];
                    $scopesProcessed++;
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                $errors[] = ($scopeNames[$sid] ?? $sid) . ": " . $e->getMessage();
                $this->publishMassProgress($legislature_id, [
                    'phase'       => 'scope_failed',
                    'phase_label' => "Scope failed: " . ($scopeNames[$sid] ?? $sid) . " ({$e->getMessage()})",
                ]);
            }
        }

        $this->publishMassProgress($legislature_id, [
            'completed'   => $halted ? $scopesProcessed : $totalScopes,
            'phase'       => $halted ? 'halted' : 'sweep_done',
            'phase_label' => $halted
                ? "Halted by operator: {$scopesProcessed}/{$totalScopes} scopes complete, {$totalCreated} districts"
                : "Sweep complete: {$scopesProcessed}/{$totalScopes} scopes, {$totalCreated} districts",
        ]);

        // Clean up the recorded backend PID — we're done, no further halts apply.
        Cache::forget("legislature.{$legislature_id}.mass_db_pid");

        return [
            'districts_created' => $totalCreated,
            'scopes_processed'  => $scopesProcessed,
            'errors'            => $errors,
            'halted'            => $halted,
            'scope_ids'         => $scopeIds,
        ];
    }

    /**
     * POST /api/legislatures/{legislature_id}/mass-halt
     *
     * Three-stage halt:
     *   1. Set the cache flag (worker polls it between scopes & between heavy
     *      ops — graceful exit if check lands during PHP-level code).
     *   2. pg_cancel_backend on the worker's recorded Postgres PID — fires
     *      a SIGINT-equivalent that most queries respect (the in-flight query
     *      throws QueryException, worker catches & exits).
     *   3. pg_terminate_backend if the query was inside a non-interruptible
     *      PostGIS C function (ST_Union, ST_Intersection on huge multipart
     *      polygons). This force-closes the connection — the worker's next
     *      DB call throws, even if the C function is still running in the
     *      Postgres backend it can no longer write a result anywhere.
     *
     * Stage 2/3 are skipped if we never recorded a PID (e.g. ran via a path
     * that bypasses executeMassReseedSweep) — graceful flag still works.
     */
    public function massHalt(string $legislature_id): JsonResponse
    {
        $leg = DB::table('legislatures')->where('id', $legislature_id)->whereNull('deleted_at')->first();
        if (!$leg) {
            return response()->json(['error' => 'Legislature not found'], 404);
        }

        // Stage 1: cache flag for graceful PHP-side exit.
        Cache::put("legislature.{$legislature_id}.mass_halt", true, 3600);

        // Stage 2: graceful Postgres cancel of the worker's in-flight query.
        // pg_cancel_backend works for most queries; the few that don't respond
        // (PostGIS C functions that don't check for interrupts) get force-
        // killed via pg_terminate_backend below.
        $pid = (int) Cache::get("legislature.{$legislature_id}.mass_db_pid");
        $cancelled = false;
        $terminated = false;
        if ($pid > 0) {
            try {
                DB::statement('SELECT pg_cancel_backend(?)', [$pid]);
                $cancelled = true;
                // Stage 3: if still active after 1.5 s, terminate the connection.
                // The worker's next DB call throws, which the job's try/catch
                // turns into a clean failed-state exit.
                $waited = 0;
                while ($waited < 1500) {
                    usleep(300000); $waited += 300;
                    $stillActive = (int) DB::scalar(
                        "SELECT COUNT(*) FROM pg_stat_activity WHERE pid = ? AND state = 'active'",
                        [$pid]
                    );
                    if ($stillActive === 0) break;
                }
                if ($stillActive ?? 0) {
                    DB::statement('SELECT pg_terminate_backend(?)', [$pid]);
                    $terminated = true;
                }
            } catch (\Throwable $e) {
                // Non-fatal — the cache flag at least signals halt requested.
                \Illuminate\Support\Facades\Log::warning(
                    'massHalt: failed to cancel/terminate backend',
                    ['legislature_id' => $legislature_id, 'pid' => $pid, 'err' => $e->getMessage()]
                );
            }
            Cache::forget("legislature.{$legislature_id}.mass_db_pid");
        }

        $this->publishMassProgress($legislature_id, [
            'phase'       => $terminated ? 'halted' : 'halting',
            'phase_label' => $terminated
                ? 'Halted by operator (terminated stuck query)'
                : ($cancelled
                    ? 'Halt requested — cancelling in-flight query'
                    : 'Halt requested — will stop after current scope'),
        ]);

        return response()->json([
            'ok'         => true,
            'cancelled'  => $cancelled,
            'terminated' => $terminated,
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

        // Clean up non-giant direct child scopes that have existing districts in this map.
        // These are ETL artifacts — non-giants should never have sub-scope districts.
        // Unconditionally safe to clear: constitutionally they cannot hold districts.
        $nonGiantQuery = DB::table('jurisdictions AS j')
            ->join('legislature_districts AS ld', 'ld.jurisdiction_id', '=', 'j.id')
            ->whereIn('j.parent_id', $scopeIds)
            ->whereNull('j.deleted_at')
            ->where('ld.legislature_id', $legislature_id)
            ->whereNull('ld.deleted_at')
            ->whereRaw('(CAST(j.population AS numeric) / ?) < ?', [$rootQuota, ConstitutionalDefaults::giantThreshold($leg->jurisdiction_id)]);
        if ($mapId !== null) {
            $nonGiantQuery->where('ld.map_id', $mapId);
        }
        $nonGiantChildIds = $nonGiantQuery->distinct()->pluck('j.id')->toArray();

        if (!empty($nonGiantChildIds)) {
            $scopeIds = array_unique(array_merge($scopeIds, $nonGiantChildIds));
        }

        $totalDisbanded  = 0;
        $scopesProcessed = 0;

        // Pre-fetch scope names for real-time progress display
        $scopeNames  = DB::table('jurisdictions')
            ->whereIn('id', $scopeIds)
            ->pluck('name', 'id')
            ->toArray();
        $totalScopes = count($scopeIds);

        DB::beginTransaction();
        try {
            foreach ($scopeIds as $scopeIdx => $sid) {
                Cache::put("legislature.{$legislature_id}.mass_progress", [
                    'current_scope' => $scopeNames[$sid] ?? $sid,
                    'completed'     => $scopeIdx,
                    'total'         => $totalScopes,
                ], 7200);
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

            // Drawn (subdivision-backed) districts were hard-deleted above WITH
            // their membership rows — retire their district_subdivisions rows
            // too (the deleteDistrict idiom), or each survives as a ghost:
            // invisible on the map, but solid to the Art. II §8 overlap gate,
            // which reads live subdivisions directly. The NOT EXISTS sweep is
            // membership-blind on purpose: it also heals any PRE-EXISTING ghost
            // at these scopes (a live subdivision none of whose memberships
            // lead to a live district — e.g. one orphaned by an earlier clear
            // that predates this retirement).
            $ghostSweep = DB::table('district_subdivisions AS ds')
                ->whereIn('ds.parent_jurisdiction_id', $scopeIds)
                ->whereNull('ds.deleted_at')
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('legislature_district_jurisdictions AS ldj')
                        ->join('legislature_districts AS ld', 'ld.id', '=', 'ldj.district_id')
                        ->whereColumn('ldj.subdivision_id', 'ds.id')
                        ->whereNull('ld.deleted_at');
                });
            if ($mapId !== null) {
                $ghostSweep->where('ds.map_id', $mapId);
            }
            $ghostSweep->update(['deleted_at' => now(), 'updated_at' => now()]);

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
            Cache::forget("legislature.{$legislature_id}.mass_progress");
        } catch (\Throwable $e) {
            DB::rollBack();
            Cache::forget("legislature.{$legislature_id}.mass_running");
            Cache::forget("legislature.{$legislature_id}.mass_progress");
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
     * GET /api/legislatures/{legislature_id}/mass-status
     *
     * Returns whether a mass reseed/disband operation is currently running.
     * Used by the frontend to poll progress when the user has navigated away.
     */
    public function massStatus(string $legislature_id): JsonResponse
    {
        return response()->json([
            'running'       => (bool) Cache::get("legislature.{$legislature_id}.mass_running", false),
            'mass_progress' => Cache::get("legislature.{$legislature_id}.mass_progress"),
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
     * map_plus_children_*: [$scopeId, ...allGiantDescendantsAtEveryDepth]
     * legislature_*:       All distinct non-null jurisdiction_ids in legislature_districts.
     *                      For _unassigned also adds $scopeId if not already present.
     */

    /**
     * BFS walk that returns every giant-scope descendant ID at any depth below $rootId.
     * A jurisdiction is "giant" when its population / rootQuota >= giant_threshold
     * (constitutional ceiling + 0.5) — meaning it needs its own sub-districting
     * scope rather than being aggregated with siblings. The walk recurses into
     * each giant so multi-level hierarchies (e.g. India → UP) are fully captured.
     */
    private function collectGiantDescendants(string $rootId, float $rootQuota, float $giantThreshold): array
    {
        $result = [];
        $queue  = [$rootId];
        $seen   = [$rootId => true];

        while (!empty($queue)) {
            $parentId = array_shift($queue);
            $children = DB::table('jurisdictions')
                ->where('parent_id', $parentId)
                ->whereNull('deleted_at')
                ->whereNotNull('geom')
                ->get(['id', 'population']);

            foreach ($children as $child) {
                if (isset($seen[$child->id])) continue;
                $seen[$child->id] = true;
                if (((int) $child->population / max($rootQuota, 1)) >= $giantThreshold) {
                    $result[] = $child->id;
                    $queue[]  = $child->id;   // recurse into this giant's children too
                }
            }
        }

        return $result;
    }

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
            // Full BFS — every giant-scope descendant at every depth.
            // This fixes the bug where India's sub-states were skipped when clearing
            // at Earth scope (only direct giant children were previously included).
            $allGiantIds = $this->collectGiantDescendants(
                $scopeId,
                $rootQuota,
                ConstitutionalDefaults::giantThreshold($leg->jurisdiction_id)
            );
            return array_merge([$scopeId], $allGiantIds);
        }

        // legislature_* — build the scope list from three sources so that a
        // fresh-start reseed (after full disband) works correctly:
        //
        //   1. Active district scopes   — scopes that already have live districts
        //                                 (handles incremental / partial reseeds)
        //   2. Apportioned sub-scopes   — jurisdictions that are members of any
        //                                 live district in this legislature
        //                                 (giant countries, provinces, etc.).
        //                                 These must be re-seeded even when the
        //                                 district table at THIS scope is empty,
        //                                 e.g. immediately after a full disband.
        //                                 Replaces the legacy
        //                                 `whereNotNull('type_a_apportioned')`
        //                                 lookup now that the column is dropped
        //                                 (migration
        //                                 2026_05_22_000002_apportionment_cleanup.php).
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

        // Sub-scopes that are members of any live district in this legislature
        // (and therefore have a locked seat budget worth re-seeding into).
        $apportionedScopes = DB::table('legislature_district_jurisdictions as ldj')
            ->join('legislature_districts as ld', 'ld.id', '=', 'ldj.district_id')
            ->where('ld.legislature_id', $legislature_id)
            ->whereNull('ld.deleted_at')
            ->distinct()
            ->pluck('ldj.jurisdiction_id')
            ->toArray();

        return array_values(array_unique(
            array_merge([$scopeId], $existingScopes, $apportionedScopes)
        ));
    }

    /**
     * Renumber all non-deleted districts for a given (legislature, scope, map) triple
     * so that district_number is a compact 1..N sequence ordered by creation date.
     *
     * Called after any create or soft-delete so draft maps never have gaps.
     * Safe to call inside or outside a transaction — uses a single UPDATE per record.
     */
    private function renumberDistricts(string $legislatureId, string $jurisdictionId, ?string $mapId): void
    {
        $query = DB::table('legislature_districts')
            ->where('legislature_id', $legislatureId)
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id');   // tie-break: stable sort

        if ($mapId !== null) {
            $query->where('map_id', $mapId);
        }

        $rows = $query->pluck('id');

        foreach ($rows as $i => $id) {
            DB::table('legislature_districts')
                ->where('id', $id)
                ->update(['district_number' => $i + 1]);
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
    /**
     * Select k seed jurisdictions using one of three strategies.
     * All strategies share the same greedy farthest-from-nearest logic for seeds 2…k;
     * only the first seed differs.
     *
     * Strategies:
     *   'northernmost' — first seed = highest latitude (original behaviour)
     *   'largest_pop'  — first seed = highest population
     *   'center_out'   — first seed = jurisdiction nearest the component's geographic centroid
     */
    private function selectSeedsByStrategy(
        string $strategy,
        array  $jids,
        array  $childById,
        array  $centroids,
        int    $k
    ): array {
        if ($k >= count($jids)) return $jids;

        switch ($strategy) {
            case 'largest_pop':
                $firstSeed = $jids[0];
                $maxPop    = -1;
                foreach ($jids as $jid) {
                    $pop = (int) ($childById[$jid]->population ?? 0);
                    if ($pop > $maxPop) { $maxPop = $pop; $firstSeed = $jid; }
                }
                break;

            case 'center_out':
                // Start from the jurisdiction nearest to the component's geographic centroid
                $cx = array_sum(array_map(fn($id) => $centroids[$id]['x'] ?? 0.0, $jids)) / count($jids);
                $cy = array_sum(array_map(fn($id) => $centroids[$id]['y'] ?? 0.0, $jids)) / count($jids);
                $firstSeed = $jids[0];
                $minDist   = PHP_FLOAT_MAX;
                foreach ($jids as $jid) {
                    $dx = ($centroids[$jid]['x'] ?? 0.0) - $cx;
                    $dy = ($centroids[$jid]['y'] ?? 0.0) - $cy;
                    $d  = $dx * $dx + $dy * $dy;
                    if ($d < $minDist) { $minDist = $d; $firstSeed = $jid; }
                }
                break;

            case 'southernmost':
                $firstSeed = $jids[0];
                $minLat    = PHP_FLOAT_MAX;
                foreach ($jids as $jid) {
                    $lat = $centroids[$jid]['y'] ?? 0.0;
                    if ($lat < $minLat) { $minLat = $lat; $firstSeed = $jid; }
                }
                break;

            case 'easternmost':
                $firstSeed = $jids[0];
                $maxLon    = PHP_FLOAT_MIN;
                foreach ($jids as $jid) {
                    $lon = $centroids[$jid]['x'] ?? 0.0;
                    if ($lon > $maxLon) { $maxLon = $lon; $firstSeed = $jid; }
                }
                break;

            case 'westernmost':
                $firstSeed = $jids[0];
                $minLon    = PHP_FLOAT_MAX;
                foreach ($jids as $jid) {
                    $lon = $centroids[$jid]['x'] ?? 0.0;
                    if ($lon < $minLon) { $minLon = $lon; $firstSeed = $jid; }
                }
                break;

            default: // 'northernmost'
                $firstSeed = $jids[0];
                $maxLat    = PHP_FLOAT_MIN;
                foreach ($jids as $jid) {
                    $lat = $centroids[$jid]['y'] ?? 0.0;
                    if ($lat > $maxLat) { $maxLat = $lat; $firstSeed = $jid; }
                }
        }

        // Seeds 2…k: greedy farthest-from-nearest (maximises minimum inter-seed distance)
        $seeds   = [$firstSeed];
        $seedSet = [$firstSeed => true];

        while (count($seeds) < $k) {
            $farthest   = null;
            $maxMinDist = -1.0;

            foreach ($jids as $jid) {
                if (isset($seedSet[$jid])) continue;

                $minDist = PHP_FLOAT_MAX;
                foreach ($seeds as $seed) {
                    $dx = ($centroids[$jid]['x'] ?? 0.0) - ($centroids[$seed]['x'] ?? 0.0);
                    $dy = ($centroids[$jid]['y'] ?? 0.0) - ($centroids[$seed]['y'] ?? 0.0);
                    $d  = $dx * $dx + $dy * $dy;
                    if ($d < $minDist) $minDist = $d;
                }

                if ($minDist > $maxMinDist) { $maxMinDist = $minDist; $farthest = $jid; }
            }

            if ($farthest === null) break;
            $seeds[]            = $farthest;
            $seedSet[$farthest] = true;
        }

        return $seeds;
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

        // Constitutional thresholds (substituted for legacy 9.5 literal).
        $giantThreshold = ConstitutionalDefaults::giantThreshold($leg->jurisdiction_id);

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
                'ld.fractional_seats', 'ld.floor_override',
                'ld.convex_hull_ratio', 'ld.is_contiguous',
                'j.id AS jid', 'j.name AS jname', 'j.iso_code AS jiso', 'j.adm_level AS jadm',
                'j.population AS jpop',
                DB::raw('(SELECT COUNT(*) FROM jurisdictions c WHERE c.parent_id = j.id AND c.deleted_at IS NULL) AS jchild_count'),
                DB::raw('(SELECT jsc.population FROM jurisdictions jsc WHERE jsc.id = ld.jurisdiction_id AND jsc.deleted_at IS NULL) AS scope_pop'),
                DB::raw('(SELECT COUNT(*) FROM jurisdictions jcc WHERE jcc.parent_id = ld.jurisdiction_id AND jcc.deleted_at IS NULL) AS scope_child_count')
            )
            ->get();

        $dmap = [];
        foreach ($dmRows as $row) {
            $did = $row->id;
            if (!isset($dmap[$did])) {
                $scopeFrac = $rootQuota > 0 ? ((float) ($row->scope_pop ?? 0)) / $rootQuota : 0;
                $dmap[$did] = [
                    'id'               => $did,
                    'seats'            => (int) $row->seats,
                    'district_number'  => (int) $row->district_number,
                    'population'       => (int) $row->actual_population,
                    'fractional_seats' => (float) $row->fractional_seats,
                    'color_index'      => 0,   // overlaid below from greedy coloring
                    'floor_override'   => (bool) $row->floor_override,
                    'convex_hull_ratio' => $row->convex_hull_ratio !== null ? round((float) $row->convex_hull_ratio, 3) : null,
                    'is_contiguous'     => $row->is_contiguous !== null ? (bool) $row->is_contiguous : null,
                    'has_integrity'     => !(($scopeFrac >= $giantThreshold) && ((int) ($row->scope_child_count ?? 0) === 0)),
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

        // Greedy adjacency-aware coloring — sourced from the legislature-wide
        // map so colors stay consistent with show() and revealedGeoJson.
        $colorMap = $this->legislatureColorMap($legislature_id, $atMapId);
        foreach ($dmap as $did => &$d) {
            $d['color_index'] = $colorMap[$did] ?? 0;
        }
        unset($d);

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
                -- type_a_apportioned populated via computeSeatBudget() in PHP
                -- after this select returns. See the show() handler for
                -- rationale — the gated cascade handles giants correctly.
                NULL                                              AS type_a_apportioned,
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
                ) AS has_districts,
                COALESCE((
                    SELECT SUM(ld3.seats)
                    FROM legislature_districts ld3
                    JOIN legislature_district_jurisdictions ldj3 ON ldj3.district_id = ld3.id
                    JOIN jurisdictions jm3 ON jm3.id = ldj3.jurisdiction_id AND jm3.deleted_at IS NULL
                    WHERE ld3.legislature_id = :leg_id2
                      AND ld3.deleted_at IS NULL
                      AND (
                          jm3.parent_id = j.id
                          OR EXISTS (
                              SELECT 1 FROM jurisdictions jp3
                              WHERE jp3.id = jm3.parent_id
                                AND jp3.parent_id = j.id
                                AND jp3.deleted_at IS NULL
                          )
                      )
                ), 0) AS child_assigned_seats
            FROM jurisdictions j
            WHERE j.parent_id = :scope_id
              AND j.deleted_at IS NULL
              AND (CAST(j.population AS numeric) * :total_seats2 / :root_pop2) >= :giant_threshold
        ", [
            'total_seats'     => $totalSeats,
            'root_pop'        => $rootPop,
            'leg_id'          => $legislature_id,
            'leg_id2'         => $legislature_id,
            'scope_id'        => $scopeId,
            'total_seats2'    => $totalSeats,
            'root_pop2'       => $rootPop,
            'giant_threshold' => $giantThreshold,
        ]);

        $giants = [];
        foreach ($childRows as $c) {
            $apportioned = $this->computeSeatBudget($c->id, $legislature_id);
            $giants[] = [
                'id'                   => $c->id,
                'name'                 => $c->name,
                'population'           => (int) $c->population,
                'fractional_seats'     => round((float) $c->fractional_seats, 2),
                'child_count'          => (int) $c->child_count,
                'has_districts'        => (bool) $c->has_districts,
                'type_a_apportioned'   => $apportioned,
                'child_assigned_seats' => (int) $c->child_assigned_seats,
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
            'deep_overages'     => [],   // over budget (delta = actual − budget)
            'incomplete_scopes' => [],   // scopes with unassigned compositable children
            // Childless leaf giants MAP-WIDE whose drawn seats < budget.
            // null = not computed (see the lazy gate at the bottom); the Vue
            // treats null/absent as OK, a positive count blocks the wizard's
            // "map complete" declaration.
            'undrawn_leaf_giants' => null,
        ];

        // Constitutional thresholds (substituted for the legacy 9.5 / 4.5 literals).
        ['giant' => $giantThreshold, 'override' => $floorOverride] = $this->thresholds($leg->jurisdiction_id);

        // Short-circuit when no districts exist in this legislature yet.
        // The deep-scan + incomplete-scopes queries below LEFT JOIN against
        // the entire 951 k-row jurisdictions table; with zero districts they
        // also produce no useful flags. The postgres container's 64 MB
        // /dev/shm can't materialize the empty-district join plan on the
        // full jurisdictions tree without exhausting shared memory. This
        // guard skips the work for empty legislatures (the natural "just
        // sized, no districts drawn yet" state immediately after
        // apportionment:seed) and returns an empty flag set.
        $hasAnyDistrict = DB::table('legislature_districts')
            ->where('legislature_id', $legId)
            ->whereNull('deleted_at')
            ->when($mapId !== null, fn ($q) => $q->where('map_id', $mapId))
            ->exists();
        if (! $hasAnyDistrict) {
            return $flags;
        }

        // ── Flag 1: Seat cap / undercount — both root and sub-scopes ────────────
        // Root scope: SUM(all legislature districts) vs. total seat budget.
        // Sub-scope:  SUM(districts created at this scope) vs. type_a_apportioned
        //             for the scope jurisdiction — so drilling into India shows
        //             "Undercount: 0/358 seats" just like the root shows "-1999".
        if ($scopeId === $leg->jurisdiction_id) {
            $capQuery = DB::table('legislature_districts')
                ->where('legislature_id', $legId)
                ->whereNull('deleted_at');
            if ($mapId !== null) {
                $capQuery->where('map_id', $mapId);
            }
            $total  = (int) $capQuery->sum('seats');
            $budget = (int) $leg->type_a_seats;
        } else {
            // Gated cascade. Returns NULL only in degenerate cases — same
            // semantics as the old lookup, with the recursion handling
            // giants correctly.
            $budget = $this->computeSeatBudget($scopeId, $legId) ?? 0;

            // Mirror the Vue's currentConfigLabel: direct districts + giant-children subtrees.
            // Giant children (frac >= giant_threshold) hold their seats at sub-scope levels;
            // we must include their child_assigned_seats (already CTE-computed in $children)
            // so the cap reflects all seats committed within India's full subtree, not just
            // India-scope districts.
            $GIANT_THRESHOLD = $giantThreshold;
            $directSeats = 0;
            foreach ($districts as $d) {
                $directSeats += (int) $d['seats'];
            }
            $giantSubtreeSeats = 0;
            foreach ($children as $child) {
                $frac = $child->fractional_seats ?? 0.0;
                if ((float) $frac >= $GIANT_THRESHOLD) {
                    $giantSubtreeSeats += (int) ($child->child_assigned_seats ?? 0);
                }
            }
            $total = $directSeats + $giantSubtreeSeats;
        }
        if ($budget > 0 && $total !== $budget) {
            $flags['cap'] = [
                'total' => $total,
                'max'   => $budget,
                'delta' => $total - $budget,
            ];
        }

        // ── Flag 4: Floor exceptions — only genuine cases ─────────────────────
        // A floor exception is meaningful only when the district's fractional seats
        // would round BELOW the floor via Webster rounding: fractional < floor - 0.5.
        // A district with fractional = floor - 0.1 still rounds to floor and does NOT need a flag.
        foreach ($districts as $d) {
            if ((float)($d['fractional_seats'] ?? $floorOverride + 1) < $floorOverride) {
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
        // a known seat budget (derived from membership in a parent-scope district),
        // provided it is the current scope or a descendant of it within 4 levels.
        //
        // The query walks DOWN from $scopeId via a recursive CTE (`scope_subtree`)
        // rather than starting from `jurisdictions` and filtering by an OR-chain of
        // p1/p2/p3 ancestors. The recursive walk is bounded by both the subtree
        // membership (small: scope + descendants) AND by `scopes_with_districts`
        // (also small: only the handful of scopes that actually have districts on
        // this map). Both sides of the final JOIN are O(districts), not O(all
        // jurisdictions). This avoids the parallel-hash buffer overflow that the
        // table-wide LEFT-JOIN variant produced on the 951 k-row jurisdictions table.
        //
        // Budget is derived from the `scope_budget` CTE: jurisdictions that appear
        // as members of any live district inherit that district's seat count.
        // Replaces the dropped `j.type_a_apportioned` lookup.
        //
        // Examples:
        //   Earth scope   → sees Earth + countries + states + counties THAT HAVE districts
        //   USA scope     → sees USA + US states + US county-level scopes (that have districts)
        //   California    → sees California + California county-level scopes (that have districts)
        $deepMapClause  = $mapId !== null ? 'AND ld.map_id = ?' : '';
        $deepMapBinding = $mapId !== null ? [$mapId] : [];

        $deepScopeRows = DB::select("
            -- Walk UP from each scope that has districts. interesting_scopes are
            -- those whose ancestor chain (up to 4 levels) contains \$scopeId — i.e.,
            -- they're inside \$scopeId's subtree. The intermediate state is bounded
            -- by O(scopes_with_districts × 4), independent of how big the
            -- jurisdictions table is.
            WITH RECURSIVE scopes_with_districts AS (
                SELECT DISTINCT ld.jurisdiction_id AS scope_id
                  FROM legislature_districts ld
                 WHERE ld.legislature_id = ?
                   AND ld.deleted_at     IS NULL
                   {$deepMapClause}
            ),
            ancestor_walk(orig_scope_id, current_id, depth) AS (
                SELECT swd.scope_id, swd.scope_id, 0
                  FROM scopes_with_districts swd
                UNION ALL
                SELECT aw.orig_scope_id, j.parent_id, aw.depth + 1
                  FROM ancestor_walk aw
                  JOIN jurisdictions j ON j.id = aw.current_id AND j.deleted_at IS NULL
                 WHERE aw.depth < 3
                   AND j.parent_id IS NOT NULL
            ),
            interesting_scopes AS (
                SELECT DISTINCT orig_scope_id AS scope_id
                  FROM ancestor_walk
                 WHERE current_id = ?
            ),
            scope_budget AS (
                SELECT ldj.jurisdiction_id, MAX(ld2.seats) AS seats
                  FROM legislature_districts ld2
                  JOIN legislature_district_jurisdictions ldj
                    ON ldj.district_id = ld2.id
                 WHERE ld2.legislature_id = ?
                   AND ld2.deleted_at IS NULL
                 GROUP BY ldj.jurisdiction_id
            )
            SELECT
                j.id                AS scope_id,
                j.name              AS scope_name,
                sb.seats            AS budget,
                COUNT(ld.id)        AS num_districts,
                SUM(ld.seats)::int  AS seat_sum,
                MAX(ld.seats)::int  AS max_seats,
                MIN(ld.seats)::int  AS min_seats,
                BOOL_OR(ld.floor_override) AS has_floor
            FROM interesting_scopes is_
            JOIN jurisdictions j ON j.id = is_.scope_id AND j.deleted_at IS NULL
            JOIN legislature_districts ld
                  ON ld.jurisdiction_id = j.id
                 AND ld.legislature_id  = ?
                 AND ld.deleted_at      IS NULL
                 {$deepMapClause}
            JOIN scope_budget sb ON sb.jurisdiction_id = j.id
            GROUP BY j.id, j.name, sb.seats
        ", array_merge(
            [$legId], $deepMapBinding,   // scopes_with_districts
            [$scopeId],                  // interesting_scopes WHERE current_id = ?
            [$legId],                    // scope_budget
            [$legId], $deepMapBinding    // outer JOIN to legislature_districts
        ));

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
            }
        }

        // ── Flag 6: Incomplete scopes — unassigned compositable children ─────
        // A scope is flagged only when:
        //   (a) it is the root scope, OR it has ≥1 district on THIS legislature+map
        //       (map-aware: avoids false positives from districts set by other maps)
        //   (b) it is NOT itself a district member (so non-giant composites like Puerto Rico
        //       are excluded — their sub-geography doesn't need separate assignment)
        //   (c) it has direct children with geometry not yet in any district on this map,
        //       where those children are NOT themselves giant drill-down scopes (identified
        //       by having their own districts on this map or being a district member).
        //
        // The query walks DOWN from $scopeId via a recursive CTE (`scope_subtree`)
        // and pre-computes the small `scopes_with_districts` and `district_members`
        // sets, then joins forward. Both sides of every JOIN are O(districts), not
        // O(all jurisdictions). Avoids the parallel-hash buffer overflow that the
        // table-wide LEFT-JOIN variant produced when run against a populated
        // legislature on the 951 k-row jurisdictions table.
        $incompMapClause = $mapId !== null ? 'AND ld.map_id = ?' : '';
        $incompMapBinding = $mapId !== null ? [$mapId] : [];
        $rootScopeId     = (string) $leg->jurisdiction_id;

        // Build the "interesting scopes" set by walking UP from each scope that
        // has districts (and from the root scope, in case the operator is sitting
        // on a freshly-apportioned legislature with zero districts). A scope is
        // interesting iff its ancestor chain within 4 levels contains \$scopeId.
        // Joins to children are bounded by O(|interesting_scopes| × avg children).
        $incompleteRows = DB::select("
            WITH RECURSIVE candidate_scopes AS (
                SELECT DISTINCT ld.jurisdiction_id AS scope_id
                  FROM legislature_districts ld
                 WHERE ld.legislature_id = ?
                   AND ld.deleted_at     IS NULL
                   {$incompMapClause}
                UNION
                SELECT ? AS scope_id   -- always consider the legislature's root scope
            ),
            ancestor_walk(orig_scope_id, current_id, depth) AS (
                SELECT cs.scope_id, cs.scope_id, 0
                  FROM candidate_scopes cs
                UNION ALL
                SELECT aw.orig_scope_id, j.parent_id, aw.depth + 1
                  FROM ancestor_walk aw
                  JOIN jurisdictions j ON j.id = aw.current_id AND j.deleted_at IS NULL
                 WHERE aw.depth < 3
                   AND j.parent_id IS NOT NULL
            ),
            interesting_scopes AS (
                SELECT DISTINCT orig_scope_id AS scope_id
                  FROM ancestor_walk
                 WHERE current_id = ?
            ),
            district_members AS (
                SELECT DISTINCT ldj.jurisdiction_id
                  FROM legislature_district_jurisdictions ldj
                  JOIN legislature_districts ld
                    ON ld.id              = ldj.district_id
                   AND ld.legislature_id  = ?
                   AND ld.deleted_at      IS NULL
                   {$incompMapClause}
            ),
            scopes_with_districts_only AS (
                SELECT DISTINCT ld.jurisdiction_id
                  FROM legislature_districts ld
                 WHERE ld.legislature_id = ?
                   AND ld.deleted_at     IS NULL
                   {$incompMapClause}
            )
            SELECT
                j.id                  AS scope_id,
                j.name                AS scope_name,
                COUNT(child.id)::int  AS unassigned_count
            FROM interesting_scopes is_
            JOIN jurisdictions j     ON j.id = is_.scope_id AND j.deleted_at IS NULL
            JOIN jurisdictions child
                  ON child.parent_id  = j.id
                 AND child.deleted_at IS NULL
                 AND child.geom       IS NOT NULL
            WHERE
                -- (a) scope is the root OR has its own districts (already filtered
                -- by interesting_scopes, but enforced again so a root-without-districts
                -- doesn't show up unless it equals \$scopeId)
                (j.id = ? OR j.id IN (SELECT jurisdiction_id FROM scopes_with_districts_only))
                -- (b) scope is NOT itself a district member
                AND j.id NOT IN (SELECT jurisdiction_id FROM district_members)
                -- (c1) child is not in any district on this map
                AND child.id NOT IN (SELECT jurisdiction_id FROM district_members)
                -- (c2) child is not itself a giant drill-down scope
                AND child.id NOT IN (SELECT jurisdiction_id FROM scopes_with_districts_only)
            GROUP BY j.id, j.name
            HAVING COUNT(child.id) > 0
        ", array_merge(
            [$legId], $incompMapBinding,   // candidate_scopes
            [$rootScopeId],                // candidate_scopes UNION root
            [$scopeId],                    // interesting_scopes WHERE current_id = ?
            [$legId], $incompMapBinding,   // district_members
            [$legId], $incompMapBinding,   // scopes_with_districts_only
            [$rootScopeId]                 // outer (a) root-or-self check
        ));

        foreach ($incompleteRows as $row) {
            $flags['incomplete_scopes'][] = [
                'scope_id'         => $row->scope_id,
                'scope_name'       => $row->scope_name,
                'unassigned_count' => (int) $row->unassigned_count,
            ];
        }

        // ── Flag 7 (Phase 5e): undrawn childless leaf giants — MAP-WIDE ──────
        // incomplete_scopes is leaf-blind: a childless giant has no children to
        // leave unassigned, so a map can read "complete" while a giant still
        // sits clamped with no drawn set. This counts every childless giant in
        // the legislature's tree whose live drawn seats fall short of its
        // budget (max(floor, round(fractional))).
        //
        // LAZY by measurement: the giant-tree walk (the wizardSteps CTE
        // pattern) benchmarked ~465 ms at Earth scope on the 951k-jurisdiction
        // box — too heavy for every mapper render. It runs ONLY when
        // incomplete_scopes came back empty: the all-done candidate path,
        // exactly the moment the wizard needs a leaf-aware veto. Otherwise the
        // flag stays null (= not computed), which readers treat as OK.
        if (count($flags['incomplete_scopes']) === 0) {
            $rootId     = (string) $leg->jurisdiction_id;
            $totalSeats = max((int) $leg->type_a_seats, 1);
            $rootPop    = max((int) DB::table('jurisdictions')->where('id', $rootId)->value('population'), 1);
            $floor      = ConstitutionalDefaults::floor($rootId);

            $undrawnMapClause = $mapId !== null ? 'AND ds.map_id = :ds_map' : '';
            $undrawnMapBind   = $mapId !== null ? ['ds_map' => $mapId] : [];

            $flags['undrawn_leaf_giants'] = (int) DB::selectOne("
                WITH RECURSIVE giant_tree AS (
                    SELECT j.id,
                           ROUND(CAST(j.population AS numeric) * :ts1 / :rp1, 4) AS frac
                      FROM jurisdictions j
                     WHERE j.parent_id = :root
                       AND j.deleted_at IS NULL
                       AND CAST(j.population AS numeric) * :ts2 / :rp2 >= :gt1
                    UNION ALL
                    SELECT j.id,
                           ROUND(CAST(j.population AS numeric) * :ts3 / :rp3, 4) AS frac
                      FROM jurisdictions j
                      JOIN giant_tree gt ON j.parent_id = gt.id
                     WHERE j.deleted_at IS NULL
                       AND CAST(j.population AS numeric) * :ts4 / :rp4 >= :gt2
                ),
                childless AS (
                    SELECT gt.id, GREATEST(:floor_s, ROUND(gt.frac)) AS budget
                      FROM giant_tree gt
                     WHERE NOT EXISTS (
                           SELECT 1 FROM jurisdictions c
                            WHERE c.parent_id = gt.id AND c.deleted_at IS NULL)
                ),
                drawn AS (
                    SELECT ds.parent_jurisdiction_id, SUM(ds.seats) AS s
                      FROM district_subdivisions ds
                     WHERE ds.deleted_at IS NULL
                       {$undrawnMapClause}
                     GROUP BY ds.parent_jurisdiction_id
                )
                SELECT count(*) AS n
                  FROM childless c
                  LEFT JOIN drawn d ON d.parent_jurisdiction_id = c.id
                 WHERE COALESCE(d.s, 0) < c.budget
            ", array_merge([
                'ts1' => $totalSeats, 'rp1' => $rootPop, 'root' => $rootId,
                'ts2' => $totalSeats, 'rp2' => $rootPop, 'gt1' => $giantThreshold,
                'ts3' => $totalSeats, 'rp3' => $rootPop,
                'ts4' => $totalSeats, 'rp4' => $rootPop, 'gt2' => $giantThreshold,
                'floor_s' => $floor,
            ], $undrawnMapBind))->n;
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
        // Allow stats computation even when no districts exist directly at this scope:
        // sub-scope districts (giant children's sub-districts) still show quality data.
        if ($quota <= 0) return $stats;

        // Constitutional giant threshold for the community-integrity check below.
        $legJid = (string) DB::table('legislatures')
            ->where('id', $legislatureId)
            ->whereNull('deleted_at')
            ->value('jurisdiction_id');
        $giantThreshold = ConstitutionalDefaults::giantThreshold($legJid);

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
                ld.fractional_seats,
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

            // Population-equality deviation is measured LOCALLY — each district's
            // integer seat count against its fractional entitlement WITHIN its own
            // apportionment scope (fractional_seats/seats - 1), identical to the
            // per-district "Dev" the sidebar shows. The earlier formula compared
            // pop/seat against the single viewed-scope quota, which for a
            // sub-district of a giant (e.g. East Java under Indonesia) folded in the
            // PARENT province's apportionment rounding: East Java's districts read
            // ~6% against Indonesia's national quota while being only ~1% off their
            // own East Java quota — so the Map Quality tiers disagreed with the
            // sidebar Devs. fractional_seats/seats is the local-normalized pop/seat
            // (= (pop/seat)/local_quota, ≈ 1.0), so it also drives a local range_ratio.
            $frac  = (float) $r->fractional_seats;
            $ratio = $frac > 0
                ? $frac / $seats                  // local: entitlement vs actual integer seats
                : ($pop / $seats) / $quota;       // fallback (same units) if fractional unset
            $deviationPct = abs($ratio - 1) * 100;
            $code = $this->makeShortCode($r->scope_name, $r->scope_iso, (int) $r->scope_adm);
            $label = $code . ' ' . str_pad((int) $r->district_number, 2, '0', STR_PAD_LEFT);

            $districtData[] = [
                'district_id'       => $r->id,
                'scope_id'          => $r->scope_id,
                'scope_name'        => $r->scope_name,
                'district_label'    => $label,
                'pop'               => $pop,
                'pop_per_seat'      => $ratio,      // local representation ratio (~1.0); drives extremes + range
                'deviation_pct'     => round($deviationPct, 2),
                'scope_pop'         => (int) $r->scope_pop,
                'scope_child_count' => (int) $r->scope_child_count,
                'member_count'      => (int) $r->member_count,
            ];
        }

        if (!empty($districtData)) {
            // Extremes: lowest ratio (more seats than entitled) = over-represented,
            // highest (fewer seats than entitled) = under-represented. pop_per_seat
            // now holds the local representation ratio (fractional_seats/seats ≈ 1.0).
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
                'range_ratio'       => round(max($popPerSeats) / max(min($popPerSeats), 1e-9), 3),
                'most_over' => [
                    'district_id'    => $mostOver['district_id'],
                    'scope_id'       => $mostOver['scope_id'],
                    'scope_name'     => $mostOver['scope_name'],
                    'district_label' => $mostOver['district_label'],
                    'deviation_pct'  => $mostOver['deviation_pct'],
                ],
                'most_under' => [
                    'district_id'    => $mostUnder['district_id'],
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
        // a "leaf giant": fractional_seats >= giant_threshold AND has no child
        // jurisdictions. These are the only cases that would require artificial
        // line-drawing tools. Giants WITH children can always be sub-districted
        // along natural borders.
        $integrityCount = 0;
        $integrityPop   = 0;
        $totalPop       = 0;
        foreach ($districtData as $d) {
            $scopeFrac   = $quota > 0 ? $d['scope_pop'] / $quota : 0;
            $isLeafGiant = ($scopeFrac >= $giantThreshold) && ($d['scope_child_count'] === 0);
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
     * Priority order:
     *   1. ADM1 (country): use iso_code directly       "USA" → "USA", "CHN" → "CHN"
     *   2. ADM2+ with hyphenated subdivision code:      "US-CA" → "CA", "CN-HB" → "HB"
     *      (geoBoundaries stores country ISO3 at every level, so subdivision codes are
     *       only present when the ETL explicitly imports them — guard with hyphen check)
     *   3. Name-based fallback: strip common geographic suffix words ("Province",
     *      "Autonomous", "Region", etc.) then:
     *        multi-word  → word initials (mb-safe)   "Uttar Pradesh" → "UP"
     *        single-word → first 3 chars (mb-safe)   "Hubei" → "HUB"
     *
     * Stripping "Province" from Chinese province names fixes the HP collision:
     *   "Hubei Province" → "HUB", "Heilongjiang Province" → "HEI" (was both "HP").
     */

    /**
     * Scope-local adjacency-aware greedy 7-coloring.
     *
     * Builds a graph where two districts are adjacent if any of their member
     * jurisdictions share a boundary (ST_Touches). Then runs a greedy color
     * assignment, sorted by degree DESC (DSATUR-flavored heuristic — color
     * the most-constrained nodes first to avoid painting yourself into a
     * corner). Returns [districtId => colorIndex].
     *
     * 4-color theorem guarantees planar maps need only 4 colors; we have 7
     * available in the palette, so greedy effectively never falls back. Cost
     * is dominated by the adjacency query: for typical scopes (≤10 districts)
     * the query returns <50 pairs and runs in microseconds; at Earth scope
     * (~270 country-level districts) ST_Touches on country geometries with
     * GIST runs in well under a second. Cached implicitly via the calling
     * endpoints' own response caches.
     *
     * Replaces the previous deterministic formula approach (which produced
     * visible adjacent-color collisions at scopes with >7 districts because
     * district_number ordering didn't track spatial adjacency).
     *
     * @param  list<string>  $districtIds  Districts to color together (one scope).
     * @return array<string,int>           Map from district_id to color_index 0..6.
     */
    private function colorIndicesForDistricts(array $districtIds): array
    {
        if (empty($districtIds)) return [];
        if (count($districtIds) === 1) return [$districtIds[0] => 0];

        // Build the district-pair adjacency graph in two SQL steps:
        //   Step 1: jurisdiction → district mapping (non-spatial, indexed).
        //   Step 2: bbox-overlap pairs among those jurisdictions (GIST-direct).
        //   Step 3 (PHP): translate jurisdiction pairs into district pairs.
        $placeholders = implode(',', array_fill(0, count($districtIds), '?'));
        $memberships = DB::select(
            "SELECT jurisdiction_id, subdivision_id, district_id
             FROM legislature_district_jurisdictions
             WHERE district_id IN ($placeholders)",
            $districtIds
        );

        // jurisdiction_id → district_id (each member is in exactly one district per scope+map).
        // DRAWN districts' memberships carry a subdivision_id and a NULL
        // jurisdiction_id — they must never enter the uuid IN-list below (a
        // NULL key would collapse to '' and poison it, 22P02). They join the
        // SAME coloring graph through their own geometry instead (the two
        // drawn-adjacency queries below), so a drawn piece and the composite
        // district touching its giant's edge can never share a color.
        $jurToDistrict = [];
        $subToDistrict = [];
        foreach ($memberships as $m) {
            if ($m->jurisdiction_id !== null) {
                $jurToDistrict[$m->jurisdiction_id] = $m->district_id;
            } elseif ($m->subdivision_id !== null) {
                $subToDistrict[$m->subdivision_id] = $m->district_id;
            }
        }
        $jurIds = array_keys($jurToDistrict);
        $subIds = array_keys($subToDistrict);

        // Step 2: which member jurisdictions are visually neighbors? We use
        // bbox overlap (`&&`) instead of full ST_Intersects/ST_Touches:
        //   - GIST index on geom makes `&&` cheap (microseconds per pair).
        //   - Full ST_Intersects on country geometries (Russia: 50k+ verts)
        //     takes ~20ms per pair → 32s+ at Earth scope.
        //   - Bbox overlap has false positives (countries on opposite sides
        //     of a sea with bboxes touching mid-water), but the consequence
        //     of a false positive is: we conservatively color them differently.
        //     That's strictly an improvement over the alternative (slow query
        //     or false negative leaving real neighbors the same color).
        $touchingPairs = [];
        if (count($jurIds) >= 2) {
            $jurPlaceholders = implode(',', array_fill(0, count($jurIds), '?'));
            $touchingPairs = DB::select(
                "SELECT j_a.id AS j_a, j_b.id AS j_b
                 FROM jurisdictions j_a
                 JOIN jurisdictions j_b ON j_a.id < j_b.id
                   AND j_a.geom && j_b.geom
                 WHERE j_a.id IN ($jurPlaceholders)
                   AND j_b.id IN ($jurPlaceholders)
                   AND j_a.deleted_at IS NULL
                   AND j_b.deleted_at IS NULL
                   AND j_a.geom IS NOT NULL
                   AND j_b.geom IS NOT NULL",
                array_merge($jurIds, $jurIds)
            );
        }

        // DRAWN (subdivision) districts join the same graph. Two edge kinds:
        //   drawn ↔ drawn siblings — the two sides of a cut share the cut line,
        //     but each side is shaved 1e-8° inward at commit (the CoveredBy
        //     epsilon defense), so exact ST_Touches misses BY CONSTRUCTION;
        //     ST_DWithin at 1e-6° (~0.1 m) absorbs the shave while staying far
        //     below any real inter-district spacing.
        //   drawn ↔ composite — a drawn piece on its giant's edge borders
        //     whatever composite member jurisdiction touches the giant there.
        // Both queries are bounded to the giant's own bbox neighborhood (the
        // sibling join is same-parent; the composite join prefilters with
        // `&& g.geom`) — never a map-wide scan. Subdivision sets are tiny.
        $drawnSiblingPairs = [];
        $drawnJurPairs     = [];
        if (count($subIds) > 0) {
            $subPh = implode(',', array_fill(0, count($subIds), '?'));
            if (count($subIds) >= 2) {
                $drawnSiblingPairs = DB::select(
                    "SELECT s_a.id AS a, s_b.id AS b
                     FROM district_subdivisions s_a
                     JOIN district_subdivisions s_b ON s_a.id < s_b.id
                       AND s_b.parent_jurisdiction_id = s_a.parent_jurisdiction_id
                       AND ST_DWithin(s_a.geom, s_b.geom, 0.000001)
                     WHERE s_a.id IN ($subPh)
                       AND s_b.id IN ($subPh)
                       AND s_a.deleted_at IS NULL AND s_b.deleted_at IS NULL
                       AND s_a.geom IS NOT NULL AND s_b.geom IS NOT NULL",
                    array_merge($subIds, $subIds)
                );
            }
            if (count($jurIds) > 0) {
                $jurPh2 = implode(',', array_fill(0, count($jurIds), '?'));
                $drawnJurPairs = DB::select(
                    "SELECT s.id AS sub_id, j.id AS jur_id
                     FROM district_subdivisions s
                     JOIN jurisdictions g ON g.id = s.parent_jurisdiction_id
                     JOIN jurisdictions j ON j.id IN ($jurPh2)
                       AND j.deleted_at IS NULL
                       AND j.geom IS NOT NULL
                       AND j.geom && g.geom
                       AND ST_DWithin(s.geom, j.geom, 0.000001)
                     WHERE s.id IN ($subPh)
                       AND s.deleted_at IS NULL
                       AND s.geom IS NOT NULL",
                    array_merge($jurIds, $subIds)
                );
            }
        }

        // Step 3: translate jurisdiction adjacency → district adjacency.
        $adj = array_fill_keys($districtIds, []);
        foreach ($touchingPairs as $p) {
            $dA = $jurToDistrict[$p->j_a] ?? null;
            $dB = $jurToDistrict[$p->j_b] ?? null;
            if ($dA && $dB && $dA !== $dB) {
                $adj[$dA][] = $dB;
                $adj[$dB][] = $dA;
            }
        }
        foreach ($drawnSiblingPairs as $p) {
            $dA = $subToDistrict[$p->a] ?? null;
            $dB = $subToDistrict[$p->b] ?? null;
            if ($dA && $dB && $dA !== $dB) {
                $adj[$dA][] = $dB;
                $adj[$dB][] = $dA;
            }
        }
        foreach ($drawnJurPairs as $p) {
            $dA = $subToDistrict[$p->sub_id] ?? null;
            $dB = $jurToDistrict[$p->jur_id] ?? null;
            if ($dA && $dB && $dA !== $dB) {
                $adj[$dA][] = $dB;
                $adj[$dB][] = $dA;
            }
        }

        // Order: highest-degree first (greedy gets better results when the
        // hardest nodes are assigned early).
        $order = $districtIds;
        usort($order, fn($a, $b) => count($adj[$b]) - count($adj[$a]));

        // Greedy color: each district takes the smallest color (0..6) not
        // used by any already-colored neighbor.
        $colors = [];
        foreach ($order as $id) {
            $taken = [];
            foreach ($adj[$id] as $nid) {
                if (isset($colors[$nid])) {
                    $taken[$colors[$nid]] = true;
                }
            }
            for ($c = 0; $c < 7; $c++) {
                if (!isset($taken[$c])) {
                    $colors[$id] = $c;
                    break;
                }
            }
            // Theoretical fallback: 7-clique (8+ mutually adjacent districts).
            // Planar maps can't have a K8, so this is unreachable in practice.
            if (!isset($colors[$id])) $colors[$id] = 0;
        }

        return $colors;
    }

    /**
     * Compute color_index for every district at a (legislature, scope, map).
     * Used by mutation endpoints to broadcast the post-mutation coloring so
     * the frontend's optimistic-update state stays in sync without a reload.
     *
     * @return array<string,int>  district_id => color_index
     */
    private function scopeColorMap(string $legislatureId, ?string $scopeId, ?string $mapId): array
    {
        // Defer to the legislature-wide coloring. We need scope-wide
        // consistency anyway (Belgium and an adjacent German Phase-4
        // sub-district must not collide even though they're at different
        // ADM levels), so we always color the whole legislature/map together.
        return $this->legislatureColorMap($legislatureId, $mapId);
    }

    /**
     * Compute color_index for ALL districts in a (legislature, map). Single
     * coloring used by BOTH show()'s sidebar and revealedGeoJson's map fills
     * so cross-ADM-level adjacencies (Belgium ↔ a German sub-district at
     * Earth scope, etc.) get distinct colors.
     *
     * Cached by legislature+map. Invalidated by flushRevealedCache().
     *
     * @return array<string,int>  district_id => color_index
     */
    private function legislatureColorMap(string $legislatureId, ?string $mapId): array
    {
        $cacheKey = "colors.{$legislatureId}." . ($mapId ?? 'null');
        return Cache::tags(["revealed.{$legislatureId}"])
            ->remember($cacheKey, 86400, function () use ($legislatureId, $mapId) {
                $ids = DB::table('legislature_districts')
                    ->where('legislature_id', $legislatureId)
                    ->whereNull('deleted_at')
                    ->when($mapId, fn($q) => $q->where('map_id', $mapId))
                    ->pluck('id')
                    ->all();
                return $this->colorIndicesForDistricts($ids);
            });
    }

    /**
     * Incrementally choose a color (0..6) for ONE district: the smallest index
     * not used by any of its spatially-adjacent neighbors.
     *
     * Adjacency is computed at the MEMBER-JURISDICTION level (member.geom &&
     * member.geom), exactly like the full colorIndicesForDistricts(), so
     * neighbors are found across ALL scopes / ADM levels — two adjacent giants'
     * sub-districts, a giant's sub-district touching a single jurisdiction inside
     * an Earth-scope composite, two Earth-scope composites, etc. all register.
     * The greedy 4-color theorem guarantees ≤4 colors suffice on a planar map;
     * with 7 available the smallest-free pick never fails in practice, and —
     * crucially — assigning a NEW node never forces an existing node to change,
     * so every other district keeps its color.
     *
     * Cost is O(this district's members × their bbox neighbors) — independent of
     * total district count, replacing the legislature-wide bbox self-join that
     * grew with every district drawn (~29s at 4,868 members → ~0.16s here).
     *
     * @param  array<string,int> $existingColors  district_id => color for already-colored districts.
     * @return int  color index 0..6.
     */
    private function colorForDistrict(string $districtId, string $legislatureId, ?string $mapId, array $existingColors): int
    {
        $mapClause = $mapId !== null ? 'AND nbr.map_id = ?' : 'AND nbr.map_id IS NULL';
        $bindings  = $mapId !== null
            ? [$districtId, $legislatureId, $mapId, $districtId]
            : [$districtId, $legislatureId, $districtId];

        $neighbors = DB::select("
            SELECT DISTINCT nbr_ldj.district_id AS nid
            FROM legislature_district_jurisdictions self_ldj
            JOIN jurisdictions self_j ON self_j.id = self_ldj.jurisdiction_id
                AND self_j.deleted_at IS NULL AND self_j.geom IS NOT NULL
            JOIN jurisdictions nbr_j ON nbr_j.id <> self_j.id
                AND nbr_j.deleted_at IS NULL AND nbr_j.geom IS NOT NULL
                AND nbr_j.geom && self_j.geom
            JOIN legislature_district_jurisdictions nbr_ldj ON nbr_ldj.jurisdiction_id = nbr_j.id
            JOIN legislature_districts nbr ON nbr.id = nbr_ldj.district_id AND nbr.deleted_at IS NULL
            WHERE self_ldj.district_id = ?
              AND nbr.legislature_id = ?
              {$mapClause}
              AND nbr_ldj.district_id <> ?
        ", $bindings);

        // DRAWN neighbors too: a new composite touching a giant with hand-drawn
        // districts must avoid their colors. Same edge rule + epsilon posture
        // as the full colorIndicesForDistricts() (the 1e-8° commit shave
        // defeats exact predicates; ST_DWithin at 1e-6° absorbs it), bounded
        // by the bbox prefilter against this district's own members.
        $mapClauseD = $mapId !== null ? 'AND nbr.map_id = ?' : 'AND nbr.map_id IS NULL';
        $bindingsD  = $mapId !== null
            ? [$districtId, $legislatureId, $mapId, $districtId]
            : [$districtId, $legislatureId, $districtId];
        $drawnNeighbors = DB::select("
            SELECT DISTINCT nbr_ldj.district_id AS nid
            FROM legislature_district_jurisdictions self_ldj
            JOIN jurisdictions self_j ON self_j.id = self_ldj.jurisdiction_id
                AND self_j.deleted_at IS NULL AND self_j.geom IS NOT NULL
            JOIN district_subdivisions ds ON ds.deleted_at IS NULL AND ds.geom IS NOT NULL
                AND ds.geom && self_j.geom
                AND ST_DWithin(ds.geom, self_j.geom, 0.000001)
            JOIN legislature_district_jurisdictions nbr_ldj ON nbr_ldj.subdivision_id = ds.id
            JOIN legislature_districts nbr ON nbr.id = nbr_ldj.district_id AND nbr.deleted_at IS NULL
            WHERE self_ldj.district_id = ?
              AND nbr.legislature_id = ?
              {$mapClauseD}
              AND nbr_ldj.district_id <> ?
        ", $bindingsD);
        $neighbors = array_merge($neighbors, $drawnNeighbors);

        $taken = [];
        foreach ($neighbors as $n) {
            if (isset($existingColors[$n->nid])) {
                $taken[$existingColors[$n->nid]] = true;
            }
        }
        for ($c = 0; $c < 7; $c++) {
            if (!isset($taken[$c])) return $c;
        }
        return 0;  // 7-clique fallback — unreachable on a planar map.
    }

    /**
     * Re-prime the legislature color cache with an incrementally-patched map,
     * using the SAME key + tag + TTL as legislatureColorMap() so a subsequent
     * read returns it without a full recompute. Called by the mutation endpoints
     * after they patch a single district's color, keeping the cache warm across
     * draws instead of letting recomputeDistrict()'s flush force a recompute.
     *
     * @param array<string,int> $colors  district_id => color index.
     */
    private function primeColorCache(string $legislatureId, ?string $mapId, array $colors): void
    {
        $cacheKey = "colors.{$legislatureId}." . ($mapId ?? 'null');
        Cache::tags(["revealed.{$legislatureId}"])->put($cacheKey, $colors, 86400);
    }

    private function makeShortCode(string $name, ?string $isoCode, int $admLevel): string
    {
        if ($isoCode) {
            if ($admLevel === 1) return strtoupper($isoCode);
            // ADM2+: only use iso_code if it is a subdivision code (contains '-')
            $pos = strrpos($isoCode, '-');
            if ($pos !== false) {
                $suffix = substr($isoCode, $pos + 1);
                if (!is_numeric($suffix)) return strtoupper($suffix);
            }
        }
        // Name-based fallback: strip common non-distinctive geographic suffix words.
        static $GENERIC = ['province', 'autonomous', 'region', 'territory', 'oblast', 'krai'];
        $words    = preg_split('/\s+/', trim($name));
        $filtered = array_values(array_filter($words, fn($w) => !in_array(mb_strtolower($w), $GENERIC)));
        $use      = count($filtered) ? $filtered : $words;
        if (count($use) > 1) {
            // Use mb_substr to safely extract the first character of each word —
            // $w[0] is byte-based and would truncate multi-byte UTF-8 characters.
            return strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), $use)));
        }
        // Use mb_substr to safely extract the first 3 characters —
        // substr($name, 0, 3) is byte-based and truncates multi-byte sequences
        // (e.g. "Jhārkhand" where ā = 2 bytes → substr produces invalid UTF-8 "JH\xc4").
        return strtoupper(mb_substr($use[0] ?? $name, 0, 3));
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
    public function flushRevealedCache(string $legislature_id, ?string $mapId, ?string $districtScopeId): void
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

    /**
     * Same as getMapId() but never returns null — auto-creates a "Draft 1"
     * map row if none exists for this legislature. Called from autoseed
     * paths so districts inserted by runAutoCompositeForScope always belong
     * to a versioned map container instead of floating with map_id = NULL.
     *
     * Naming: "Draft N" where N is one more than the highest existing
     * Draft-numbered map (resilient to manual map creation by the operator).
     */
    private function ensureMapId(string $legislature_id, ?string $requestedMapId): string
    {
        $existing = $this->getMapId($legislature_id, $requestedMapId);
        if ($existing !== null) {
            return $existing;
        }

        // Find the highest existing "Draft N" so we don't collide.
        $existingNames = DB::table('legislature_district_maps')
            ->where('legislature_id', $legislature_id)
            ->whereNull('deleted_at')
            ->pluck('name')
            ->toArray();
        $maxDraftN = 0;
        foreach ($existingNames as $n) {
            if (preg_match('/^Draft\s+(\d+)\s*$/i', (string) $n, $m)) {
                $maxDraftN = max($maxDraftN, (int) $m[1]);
            }
        }
        $name = 'Draft ' . ($maxDraftN + 1);

        $newId = (string) Str::uuid();
        DB::table('legislature_district_maps')->insert([
            'id'             => $newId,
            'legislature_id' => $legislature_id,
            'name'           => $name,
            'description'    => 'Auto-created on first autoseed run.',
            'status'         => 'draft',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        return $newId;
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

    // ── Wizard Stepper ────────────────────────────────────────────────────────

    /**
     * Returns a post-order depth-first sequence of all "giant" scopes
     * (fractional_seats ≥ 9.5 relative to the root quota) for the given
     * legislature, with giants sorted largest-first at every level.
     * Root scope is always appended as the final step.
     *
     * GET /api/legislatures/{legislature_id}/wizard-steps
     *   ?scope_id=<uuid>   (optional, used to compute current_index)
     */
    public function wizardSteps(Request $request, string $legislature_id): JsonResponse
    {
        $leg = DB::table('legislatures')
            ->where('id', $legislature_id)
            ->whereNull('deleted_at')
            ->first();
        if (!$leg) {
            return response()->json(['error' => 'Legislature not found'], 404);
        }

        $rootId         = $leg->jurisdiction_id;
        $totalSeats     = (int) $leg->type_a_seats;
        $rootPop        = (int) DB::scalar('SELECT population FROM jurisdictions WHERE id = ?', [$rootId]);
        $giantThreshold = ConstitutionalDefaults::giantThreshold($rootId);

        if ($rootPop <= 0) {
            return response()->json(['steps' => [['scope_id' => $rootId, 'scope_name' => '']], 'current_index' => 0]);
        }

        // Single recursive CTE collects every giant scope in the tree
        $rows = DB::select("
            WITH RECURSIVE giant_tree AS (
                SELECT j.id, j.name, j.parent_id,
                       ROUND(CAST(j.population AS numeric) * :ts1 / :rp1, 4) AS fractional_seats
                FROM jurisdictions j
                WHERE j.parent_id = :root
                  AND j.deleted_at IS NULL
                  AND CAST(j.population AS numeric) * :ts2 / :rp2 >= :gt1
                UNION ALL
                SELECT j.id, j.name, j.parent_id,
                       ROUND(CAST(j.population AS numeric) * :ts3 / :rp3, 4) AS fractional_seats
                FROM jurisdictions j
                JOIN giant_tree gt ON j.parent_id = gt.id
                WHERE j.deleted_at IS NULL
                  AND CAST(j.population AS numeric) * :ts4 / :rp4 >= :gt2
            )
            SELECT id, name, parent_id, fractional_seats FROM giant_tree
        ", [
            'ts1' => $totalSeats, 'rp1' => $rootPop, 'root' => $rootId,
            'ts2' => $totalSeats, 'rp2' => $rootPop,
            'ts3' => $totalSeats, 'rp3' => $rootPop,
            'ts4' => $totalSeats, 'rp4' => $rootPop,
            'gt1' => $giantThreshold, 'gt2' => $giantThreshold,
        ]);

        // Build adjacency list: parent_id → children sorted by fractional_seats DESC
        $adj = [];
        foreach ($rows as $r) {
            $adj[$r->parent_id][] = $r;
        }
        foreach ($adj as &$kids) {
            usort($kids, fn($a, $b) => (float)$b->fractional_seats <=> (float)$a->fractional_seats);
        }
        unset($kids);

        // Post-order DFS: children before parent, largest-first within each level
        $steps = [];
        $this->postOrderGiants($rootId, $adj, $steps);

        // Root scope is always the final step
        $rootName = DB::scalar('SELECT name FROM jurisdictions WHERE id = ?', [$rootId]);
        $steps[] = ['scope_id' => $rootId, 'scope_name' => (string) $rootName];

        // Find the index for the requested scope
        $currentId  = $request->query('scope_id', $rootId);
        $currentIdx = count($steps) - 1;  // default to root (last)
        foreach ($steps as $i => $s) {
            if ($s['scope_id'] === $currentId) {
                $currentIdx = $i;
                break;
            }
        }

        return response()->json(['steps' => $steps, 'current_index' => $currentIdx]);
    }

    private function postOrderGiants(string $scopeId, array &$adj, array &$steps): void
    {
        foreach ($adj[$scopeId] ?? [] as $child) {
            $this->postOrderGiants($child->id, $adj, $steps);
            $steps[] = ['scope_id' => $child->id, 'scope_name' => (string) $child->name];
        }
    }
}
