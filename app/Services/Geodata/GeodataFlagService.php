<?php

namespace App\Services\Geodata;

use App\Models\GeodataFlag;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GeodataFlagService — the stored-flag sibling of DataReviewService.
 *
 * Where DataReviewService builds a read-only review surface on demand, this
 * service SCANS the jurisdictions tree for structural geodata defects and
 * persists each finding as a geodata_flags row that the repair plane
 * (GeodataRemediationService + the Jurisdiction Viewer queue) acts on.
 *
 * Six detectors, each shaped against the 951k-row world import:
 *
 *   dual_coverage         L1 row whose surface point is also covered by a
 *                         foreign iso tree (ISO-tree independence: intended
 *                         dual coverage unless the operator prunes one side)
 *   mis_anchored_cluster  a parent holding >=3 children that lie essentially
 *                         outside it (the Martinique-communes-under-
 *                         Marie-Galante class) → synthesize a proper anchor
 *   same_space_chain      single-child parent/child pairs occupying the same
 *                         space (md5-identical or ~equal area), chained into
 *                         maximal runs → merge, topmost owns
 *   raster_coverage       L1 isos with no WorldPop tiles, or with tiles but a
 *                         population far below the raster total (GRL class)
 *   displaced_geometry    individual rows outside their parent (not part of
 *                         a mis-anchored cluster) → reparent
 *   orphaned_rows         live rows with no live parent, grouped by
 *                         (adm_level, iso)
 *
 * Scan mechanics: open flags of a scanned category are derived artifacts —
 * they are hard-deleted and re-detected on every scan. Accepted/resolved
 * flags persist: a finding whose fingerprint matches one is skipped, so an
 * operator's "accepted" dual-coverage stays accepted across rescans.
 *
 * Detector queries never load geometry into PHP; expensive area math always
 * runs behind a MATERIALIZED prefilter (NOT ST_Covers) so it only touches
 * the tiny suspicious subset.
 */
class GeodataFlagService
{
    /** Cache key holding {running, started_at, finished_at, progress, last_summary}. */
    private const STATUS_CACHE_KEY = 'geodata.scan.status';

    /** displaced_geometry emits at most this many flags per scan. */
    private const DISPLACED_CAP = 500;

    /**
     * Live-row predicate applied to every jurisdictions reference: not
     * soft-deleted AND not a merged-away chain member.
     */
    private function live(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : "{$alias}.";

        return "{$prefix}deleted_at IS NULL AND {$prefix}merged_into_id IS NULL";
    }

    /**
     * Run the requested detectors (all six when $categories is null),
     * optionally restricted to a set of ISO codes (used by tests and
     * targeted rescans after a repair).
     *
     * @param  list<string>|null  $categories  subset of GeodataFlag::CATEGORIES
     * @param  list<string>|null  $isoCodes    e.g. ['FRA', 'MTQ']
     * @return array<string,int>  per-category count of open flags inserted
     */
    public function scan(?array $categories = null, ?array $isoCodes = null): array
    {
        // Canonical order matters: displaced_geometry excludes rows already
        // claimed by a mis_anchored_cluster flag, so clusters detect first.
        $categories = $categories === null || $categories === []
            ? GeodataFlag::CATEGORIES
            : array_values(array_intersect(GeodataFlag::CATEGORIES, $categories));

        $isoCodes = $this->normalizeIsoCodes($isoCodes);

        $startedAt = now()->toIso8601String();
        $previous  = Cache::get(self::STATUS_CACHE_KEY, []);
        Cache::forever(self::STATUS_CACHE_KEY, [
            'running'      => true,
            'started_at'   => $startedAt,
            'finished_at'  => null,
            'progress'     => [],
            'last_summary' => $previous['last_summary'] ?? null,
        ]);

        $counts = [];

        try {
            foreach ($categories as $category) {
                // Detect FIRST, then swap atomically: deleting the open flags
                // before a detector that later throws would silently wipe the
                // category's queue. Accepted/resolved flags are operator state
                // and survive either way.
                $findings = match ($category) {
                    'dual_coverage'        => $this->detectDualCoverage($isoCodes),
                    'mis_anchored_cluster' => $this->detectMisAnchoredClusters($isoCodes),
                    'same_space_chain'     => $this->detectSameSpaceChains($isoCodes),
                    'raster_coverage'      => $this->detectRasterCoverage($isoCodes),
                    'displaced_geometry'   => $this->detectDisplacedGeometry($isoCodes),
                    'orphaned_rows'        => $this->detectOrphanedRows($isoCodes),
                };

                $counts[$category] = DB::transaction(function () use ($category, $isoCodes, $findings) {
                    $this->deleteOpenFlags($category, $isoCodes);

                    return $this->insertFlags($category, $findings);
                });

                Cache::forever(self::STATUS_CACHE_KEY, [
                    'running'      => true,
                    'started_at'   => $startedAt,
                    'finished_at'  => null,
                    'progress'     => $counts,
                    'last_summary' => $previous['last_summary'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            Cache::forever(self::STATUS_CACHE_KEY, [
                'running'      => false,
                'started_at'   => $startedAt,
                'finished_at'  => now()->toIso8601String(),
                'progress'     => $counts,
                'last_summary' => [
                    'error'      => $e->getMessage(),
                    'counts'     => $counts,
                    'categories' => $categories,
                    'iso_codes'  => $isoCodes,
                ],
            ]);
            throw $e;
        }

        $finishedAt = now()->toIso8601String();
        Cache::forever(self::STATUS_CACHE_KEY, [
            'running'      => false,
            'started_at'   => $startedAt,
            'finished_at'  => $finishedAt,
            'progress'     => $counts,
            'last_summary' => [
                'counts'      => $counts,
                'categories'  => $categories,
                'iso_codes'   => $isoCodes,
                'started_at'  => $startedAt,
                'finished_at' => $finishedAt,
            ],
        ]);

        return $counts;
    }

    /**
     * Current scan status for the UI poller.
     *
     * @return array{running:bool, started_at:?string, finished_at:?string, last_summary:?array}
     */
    public function status(): array
    {
        $status = Cache::get(self::STATUS_CACHE_KEY);

        return [
            'running'      => (bool) ($status['running'] ?? false),
            'started_at'   => $status['started_at'] ?? null,
            'finished_at'  => $status['finished_at'] ?? null,
            'progress'     => $status['progress'] ?? [],
            'last_summary' => $status['last_summary'] ?? null,
        ];
    }

    // ─── Detector 1: dual_coverage ───────────────────────────────────────────

    /**
     * L1 rows whose representative surface point is covered by rows of a
     * DIFFERENT iso tree at adm_level>=2. One flag per (row, host_iso).
     * Info-severity: the ISO-tree independence rule means overlap is intended
     * dual coverage unless the operator prunes one side.
     */
    private function detectDualCoverage(?array $isoCodes): array
    {
        [$isoSql, $bindings] = $this->isoClause('iso_code', $isoCodes, 'l1isos');

        $rows = DB::select(
            "
            WITH l1 AS MATERIALIZED (
                SELECT id, slug, name, iso_code, COALESCE(population, 0) AS own_pop,
                       ST_PointOnSurface(geom) AS pos
                FROM jurisdictions
                WHERE " . $this->live() . "
                  AND adm_level = 1 AND geom IS NOT NULL
                  {$isoSql}
            )
            SELECT l1.id::text AS jurisdiction_id,
                   l1.slug, l1.name, l1.iso_code, l1.own_pop,
                   r.iso_code  AS host_iso,
                   array_to_json((array_agg(r.slug ORDER BY r.slug))[1:5])::text AS sample_host_slugs
            FROM l1
            JOIN jurisdictions r
              ON " . $this->live('r') . "
             AND r.adm_level >= 2
             AND r.iso_code IS NOT NULL
             AND r.iso_code <> l1.iso_code
             AND ST_Covers(r.geom, l1.pos)
            GROUP BY l1.id, l1.slug, l1.name, l1.iso_code, l1.own_pop, r.iso_code
            ",
            $bindings
        );

        $findings = [];
        foreach ($rows as $row) {
            $findings[] = [
                'severity'         => 'info',
                'jurisdiction_id'  => $row->jurisdiction_id,
                'title'            => "{$row->name} is also covered by the {$row->host_iso} tree",
                'suggested_action' => 'accept_flag',
                'fingerprint'      => $this->fingerprint('dual_coverage', [$row->slug], (string) $row->host_iso),
                'payload'          => [
                    'iso'               => $row->iso_code,
                    'slug'              => $row->slug,
                    'host_iso'          => $row->host_iso,
                    'sample_host_slugs' => json_decode($row->sample_host_slugs, true) ?: [],
                    'own_pop'           => (int) $row->own_pop,
                    'note'              => 'ISO-tree independence rule: overlap is intended dual coverage unless pruned',
                ],
            ];
        }

        return $findings;
    }

    // ─── Detector 2: mis_anchored_cluster ────────────────────────────────────

    /**
     * Parents holding >=3 live children whose centroid falls outside the
     * parent AND whose area overlap with it is < 5% — a whole cluster
     * anchored on the wrong row (verified case: 34 Martinique communes under
     * fra-5-marie-galante). Suggested repair: synthesize a proper anchor.
     *
     * The cheap NOT ST_Covers prefilter is MATERIALIZED so the expensive
     * ST_Intersection area math only runs on pairs that already failed it.
     */
    private function detectMisAnchoredClusters(?array $isoCodes): array
    {
        $bindings = [];
        $isoSql = '';
        if ($isoCodes !== null) {
            $arr = $this->pgTextArray($isoCodes);
            $isoSql = 'AND (p.iso_code = ANY(CAST(:pisos AS text[])) OR c.iso_code = ANY(CAST(:cisos AS text[])))';
            $bindings = ['pisos' => $arr, 'cisos' => $arr];
        }

        $rows = DB::select(
            "
            WITH pre AS MATERIALIZED (
                SELECT c.id AS child_id, c.parent_id
                FROM jurisdictions c
                JOIN jurisdictions p ON p.id = c.parent_id
                WHERE " . $this->live('c') . "
                  AND " . $this->live('p') . "
                  AND c.centroid IS NOT NULL AND c.geom IS NOT NULL AND p.geom IS NOT NULL
                  AND NOT ST_Covers(p.geom, c.centroid)
                  {$isoSql}
            ),
            scored AS MATERIALIZED (
                SELECT p.id AS parent_id, p.slug AS parent_slug, p.name AS parent_name,
                       p.iso_code AS parent_iso,
                       c.slug AS child_slug, c.iso_code AS child_iso,
                       COALESCE(c.population, 0) AS child_pop
                FROM pre
                JOIN jurisdictions c ON c.id = pre.child_id
                JOIN jurisdictions p ON p.id = pre.parent_id
                WHERE COALESCE(ST_Area(ST_Intersection(c.geom, p.geom)) / NULLIF(ST_Area(c.geom), 0), 0) < 0.05
            )
            SELECT parent_id::text AS parent_id, parent_slug, parent_name, parent_iso,
                   COUNT(*)::int AS child_count,
                   SUM(child_pop)::bigint AS children_pop_sum,
                   mode() WITHIN GROUP (ORDER BY child_iso) AS common_child_iso,
                   array_to_json(array_agg(child_slug ORDER BY child_slug))::text AS child_slugs
            FROM scored
            GROUP BY parent_id, parent_slug, parent_name, parent_iso
            HAVING COUNT(*) >= 3
            ",
            $bindings
        );

        $findings = [];
        $anchorParents = [];
        foreach ($rows as $row) {
            $childSlugs = json_decode($row->child_slugs, true) ?: [];

            // The RIGHT parent for a synthesized anchor is the cluster's own
            // country row — NOT $row->parent_slug, which is the mis-anchoring
            // row the cluster must escape (prefilling the modal with it would
            // rebuild the defect one level up).
            $iso = $row->common_child_iso;
            if ($iso !== null && ! array_key_exists($iso, $anchorParents)) {
                $anchorParents[$iso] = DB::table('jurisdictions')
                    ->where('iso_code', $iso)
                    ->where('adm_level', 1)
                    ->whereNull('deleted_at')
                    ->whereNull('merged_into_id')
                    ->value('slug');
            }

            $findings[] = [
                'severity'         => 'critical',
                'jurisdiction_id'  => $row->parent_id,
                'title'            => "\"{$row->parent_name}\" anchors {$row->child_count} children that lie outside it",
                'suggested_action' => 'synthesize_anchor',
                'fingerprint'      => $this->fingerprint('mis_anchored_cluster', $childSlugs, (string) $row->parent_slug),
                'payload'          => [
                    'iso'                     => $row->common_child_iso,
                    'parent_iso'              => $row->parent_iso,
                    'parent_slug'             => $row->parent_slug,
                    'suggested_anchor_parent' => $iso !== null ? ($anchorParents[$iso] ?? null) : null,
                    'child_slugs'             => $childSlugs,
                    'child_count'             => (int) $row->child_count,
                    'children_pop_sum'        => (int) $row->children_pop_sum,
                    'common_child_iso'        => $row->common_child_iso,
                ],
            ];
        }

        return $findings;
    }

    // ─── Detector 3: same_space_chain ────────────────────────────────────────

    /**
     * Single-child parent/child pairs occupying the same space (md5-identical
     * geometry, or child area within 98–102% of the parent's AND a symmetric
     * difference under 1% of the larger footprint — the exact predicate
     * GeodataRemediationService::sameSpace() re-validates at merge time, so
     * every flagged chain is mergeable by construction), chained into
     * maximal runs. One flag per chain, anchored on the topmost member.
     *
     * The verified census is ~13k pairs / ~3k md5 twins, so pair rows come
     * back to PHP as scalars only (never geometry) and chain assembly is a
     * linear walk over an id-keyed map.
     */
    private function detectSameSpaceChains(?array $isoCodes): array
    {
        [$isoSql, $bindings] = $this->isoClause('p.iso_code', $isoCodes, 'pisos');

        $pairs = DB::select(
            "
            WITH only_children AS MATERIALIZED (
                SELECT parent_id AS pid, (array_agg(id))[1] AS cid
                FROM jurisdictions
                WHERE " . $this->live() . " AND parent_id IS NOT NULL
                GROUP BY parent_id
                HAVING COUNT(*) = 1
            )
            SELECT p.id::text AS parent_id, p.slug AS parent_slug, p.name AS parent_name,
                   p.iso_code AS parent_iso, COALESCE(p.population, 0) AS parent_pop,
                   c.id::text AS child_id, c.slug AS child_slug,
                   COALESCE(c.population, 0) AS child_pop,
                   (md5(ST_AsBinary(p.geom)) = md5(ST_AsBinary(c.geom))) AS md5_twin
            FROM only_children oc
            JOIN jurisdictions p ON p.id = oc.pid
             AND " . $this->live('p') . "
            JOIN jurisdictions c ON c.id = oc.cid
            WHERE p.geom IS NOT NULL AND c.geom IS NOT NULL
              {$isoSql}
              AND (CASE
                     -- CASE forces evaluation order (PG does not guarantee OR/AND
                     -- short-circuit): the ~3k md5 twins skip ST_SymDifference
                     -- entirely, and the cheap area-ratio band gates the expensive
                     -- symmetric difference for everything else.
                     WHEN md5(ST_AsBinary(p.geom)) = md5(ST_AsBinary(c.geom)) THEN true
                     WHEN (ST_Area(c.geom) / NULLIF(ST_Area(p.geom), 0)) BETWEEN 0.98 AND 1.02
                          THEN ST_Area(ST_SymDifference(p.geom, c.geom))
                               <= 0.01 * NULLIF(GREATEST(ST_Area(p.geom), ST_Area(c.geom)), 0)
                     ELSE false
                   END)
            ",
            $bindings
        );

        // Chain consecutive pairs (a.child = b.parent) into maximal runs.
        $byParent = [];
        $childIds = [];
        foreach ($pairs as $pair) {
            $byParent[$pair->parent_id] = $pair;
            $childIds[$pair->child_id]  = true;
        }

        $findings = [];
        foreach ($byParent as $parentId => $top) {
            if (isset($childIds[$parentId])) {
                continue; // not the topmost member of its run
            }

            $chainSlugs = [$top->parent_slug];
            $pops       = [$top->parent_slug => (int) $top->parent_pop];
            $md5Twin    = true;
            $lastChildId = null;

            $cursor = $top;
            while ($cursor !== null) {
                $chainSlugs[] = $cursor->child_slug;
                $pops[$cursor->child_slug] = (int) $cursor->child_pop;
                $md5Twin = $md5Twin && (bool) $cursor->md5_twin;
                $lastChildId = $cursor->child_id;
                $cursor = $byParent[$cursor->child_id] ?? null;
            }

            $depth = count($chainSlugs);
            $findings[] = [
                'severity'                => 'warning',
                'jurisdiction_id'         => $top->parent_id,
                'related_jurisdiction_id' => $lastChildId,
                'title'                   => "\"{$top->parent_name}\" heads a chain of {$depth} same-space rows",
                'suggested_action'        => 'merge_chain',
                'fingerprint'             => $this->fingerprint('same_space_chain', $chainSlugs, (string) $depth),
                'payload'                 => [
                    'iso'         => $top->parent_iso,
                    'chain_slugs' => $chainSlugs, // topmost first — the merge_chain input order
                    'depth'       => $depth,
                    'md5_twin'    => $md5Twin,
                    'pops'        => $pops,
                ],
            ];
        }

        return $findings;
    }

    // ─── Detector 4: raster_coverage ─────────────────────────────────────────

    /**
     * (a) L1 isos with ZERO WorldPop tiles: critical when the row claims a
     *     real population (< 1000 is the "probably uninhabited" line) AND has
     *     live descendants (XKX class — a populated tree with no raster);
     *     info otherwise (ATA/VAT class — genuinely empty).
     * (b) L1 isos WITH tiles whose stored population is under half the raster
     *     total (GRL class: ratio 0.007) → recompute_population.
     */
    private function detectRasterCoverage(?array $isoCodes): array
    {
        $findings = [];

        // (a) — no tiles at all for this iso.
        [$isoSqlA, $bindingsA] = $this->isoClause('j.iso_code', $isoCodes, 'aisos');
        $noTiles = DB::select(
            "
            SELECT j.id::text AS jurisdiction_id, j.slug, j.name, j.iso_code,
                   COALESCE(j.population, 0) AS population,
                   EXISTS (
                       SELECT 1 FROM jurisdictions c
                       WHERE c.parent_id = j.id
                         AND " . $this->live('c') . "
                   ) AS has_descendants
            FROM jurisdictions j
            WHERE " . $this->live('j') . "
              AND j.adm_level = 1 AND j.iso_code IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM worldpop_rasters w WHERE w.iso_code = j.iso_code)
              {$isoSqlA}
            ",
            $bindingsA
        );

        foreach ($noTiles as $row) {
            // A populated tree with no raster can't be raster-checked at all
            // (critical); a low-pop leaf with no raster is expected (info).
            $critical = ((int) $row->population) < 1000 && $row->has_descendants;
            $findings[] = [
                'severity'         => $critical ? 'critical' : 'info',
                'jurisdiction_id'  => $row->jurisdiction_id,
                'title'            => "{$row->name} ({$row->iso_code}) has no WorldPop raster tiles",
                'suggested_action' => $critical ? 'recompute_population' : 'accept_flag',
                'fingerprint'      => $this->fingerprint('raster_coverage', [$row->slug], 'no_tiles'),
                'payload'          => [
                    'iso'        => $row->iso_code,
                    'slug'       => $row->slug,
                    'tiles'      => 0,
                    'population' => (int) $row->population,
                ],
            ];
        }

        // (b) — tiles exist but stored population is < 50% of the raster
        // total. Skipped entirely when no rasters are loaded (a raster-less
        // install would otherwise flag every country).
        $rasterCount = (int) DB::scalar('SELECT COUNT(*) FROM worldpop_rasters');
        if ($rasterCount === 0) {
            return $findings;
        }

        $bindingsB = [];
        $isoSqlB = '';
        if ($isoCodes !== null) {
            $isoSqlB = 'AND w.iso_code = ANY(CAST(:bisos AS text[]))';
            $bindingsB = ['bisos' => $this->pgTextArray($isoCodes)];
        }

        $undercounts = DB::select(
            "
            WITH totals AS MATERIALIZED (
                -- Latest vintage per iso only (mirrors population_within):
                -- summing across years would double every total the moment a
                -- second WorldPop year lands, flagging all ~230 countries.
                SELECT iso_code, SUM((ST_SummaryStats(rast)).sum) AS raster_total
                FROM worldpop_rasters w
                WHERE w.year = (SELECT MAX(year) FROM worldpop_rasters w2 WHERE w2.iso_code = w.iso_code)
                {$isoSqlB}
                GROUP BY iso_code
            )
            SELECT j.id::text AS jurisdiction_id, j.slug, j.name, j.iso_code,
                   COALESCE(j.population, 0) AS population,
                   t.raster_total
            FROM jurisdictions j
            JOIN totals t ON t.iso_code = j.iso_code
            WHERE " . $this->live('j') . "
              AND j.adm_level = 1
              AND (COALESCE(j.population, 0))::float / NULLIF(t.raster_total, 0) < 0.5
            ",
            $bindingsB
        );

        foreach ($undercounts as $row) {
            $rasterTotal = (float) $row->raster_total;
            $ratio = $rasterTotal > 0
                ? round(((int) $row->population) / $rasterTotal, 4)
                : null;
            $findings[] = [
                'severity'         => 'critical',
                'jurisdiction_id'  => $row->jurisdiction_id,
                'title'            => "{$row->name} ({$row->iso_code}) population is far below its raster total",
                'suggested_action' => 'recompute_population',
                'fingerprint'      => $this->fingerprint('raster_coverage', [$row->slug], 'undercount'),
                'payload'          => [
                    'iso'          => $row->iso_code,
                    'slug'         => $row->slug,
                    'population'   => (int) $row->population,
                    'raster_total' => $rasterTotal,
                    'ratio'        => $ratio,
                ],
            ];
        }

        return $findings;
    }

    // ─── Detector 5: displaced_geometry ──────────────────────────────────────

    /**
     * Individual rows (adm_level>=2) whose centroid is outside their parent
     * and whose area overlap is < 50% — but NOT part of a mis-anchored
     * cluster (those get one cluster flag, not N row flags). Each flag
     * carries the top 3 same-iso candidate parents one level up by centroid
     * distance (planar KNN — uses the centroid GiST index).
     */
    private function detectDisplacedGeometry(?array $isoCodes): array
    {
        // Rows already claimed by an open OR ACCEPTED mis_anchored_cluster
        // flag (the cluster detector runs earlier in the same scan, so its
        // fresh flags are in the table by now; an operator-accepted cluster
        // must keep suppressing its members too, or accepting one cluster
        // floods the next scan with N per-row displaced flags). Resolved
        // clusters need no exclusion — their repair re-homed the children.
        $excluded = [];
        foreach (GeodataFlag::query()->whereIn('status', ['open', 'accepted'])->where('category', 'mis_anchored_cluster')->get() as $flag) {
            foreach ((array) ($flag->payload['child_slugs'] ?? []) as $slug) {
                $excluded[$slug] = true;
            }
        }
        $excludedArr = $this->pgTextArray(array_keys($excluded));

        [$isoSql, $isoBindings] = $this->isoClause('c.iso_code', $isoCodes, 'cisos');
        $limit = self::DISPLACED_CAP + 1; // +1 to detect truncation

        $rows = DB::select(
            "
            WITH pre AS MATERIALIZED (
                SELECT c.id AS child_id, c.parent_id
                FROM jurisdictions c
                JOIN jurisdictions p ON p.id = c.parent_id
                WHERE " . $this->live('c') . "
                  AND " . $this->live('p') . "
                  AND c.adm_level >= 2
                  AND c.centroid IS NOT NULL AND c.geom IS NOT NULL AND p.geom IS NOT NULL
                  AND NOT ST_Covers(p.geom, c.centroid)
                  {$isoSql}
            ),
            scored AS MATERIALIZED (
                SELECT c.id, c.slug, c.name, c.iso_code, c.adm_level, c.centroid,
                       p.slug AS parent_slug,
                       COALESCE(ST_Area(ST_Intersection(c.geom, p.geom)) / NULLIF(ST_Area(c.geom), 0), 0) AS overlap_ratio
                FROM pre
                JOIN jurisdictions c ON c.id = pre.child_id
                JOIN jurisdictions p ON p.id = pre.parent_id
            )
            SELECT s.id::text AS jurisdiction_id, s.slug, s.name, s.iso_code,
                   s.overlap_ratio, s.parent_slug,
                   cand.candidates
            FROM scored s
            LEFT JOIN LATERAL (
                SELECT array_to_json(array_agg(row_to_json(x)))::text AS candidates
                FROM (
                    SELECT x.slug, x.name
                    FROM jurisdictions x
                    WHERE " . $this->live('x') . "
                      AND x.iso_code = s.iso_code
                      AND x.adm_level = s.adm_level - 1
                      AND x.centroid IS NOT NULL
                      AND x.id <> s.id
                    ORDER BY x.centroid <-> s.centroid
                    LIMIT 3
                ) x
            ) cand ON true
            WHERE s.overlap_ratio < 0.5
              AND NOT (s.slug = ANY(CAST(:excluded AS text[])))
            ORDER BY s.overlap_ratio ASC
            LIMIT {$limit}
            ",
            array_merge($isoBindings, ['excluded' => $excludedArr])
        );

        $truncated = count($rows) > self::DISPLACED_CAP;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::DISPLACED_CAP);
        }

        $findings = [];
        foreach ($rows as $row) {
            $payload = [
                'iso'               => $row->iso_code,
                'slug'              => $row->slug,
                'overlap_ratio'     => round((float) $row->overlap_ratio, 4),
                'parent_slug'       => $row->parent_slug,
                'candidate_parents' => json_decode($row->candidates ?? '[]', true) ?: [],
            ];
            if ($truncated) {
                $payload['note'] = 'scan capped at ' . self::DISPLACED_CAP . ' displaced_geometry flags — more exist; rescan after repairs';
            }
            $findings[] = [
                'severity'         => 'warning',
                'jurisdiction_id'  => $row->jurisdiction_id,
                'title'            => "\"{$row->name}\" lies outside its parent {$row->parent_slug}",
                'suggested_action' => 'reparent',
                'fingerprint'      => $this->fingerprint('displaced_geometry', [$row->slug], (string) $row->parent_slug),
                'payload'          => $payload,
            ];
        }

        return $findings;
    }

    // ─── Detector 6: orphaned_rows ───────────────────────────────────────────

    /**
     * Live rows (adm_level > 0) with no parent, or whose parent is
     * soft-deleted. Grouped into one flag per (adm_level, iso) so a
     * whole-country lineage clear reads as a handful of flags, not 40k.
     */
    private function detectOrphanedRows(?array $isoCodes): array
    {
        [$isoSql, $bindings] = $this->isoClause('j.iso_code', $isoCodes, 'oisos');

        $rows = DB::select(
            "
            SELECT j.adm_level, j.iso_code,
                   COUNT(*)::int AS cnt,
                   array_to_json((array_agg(j.slug ORDER BY j.slug))[1:5])::text AS sample_slugs
            FROM jurisdictions j
            LEFT JOIN jurisdictions p ON p.id = j.parent_id
            WHERE " . $this->live('j') . "
              AND j.adm_level > 0
              AND (j.parent_id IS NULL OR p.deleted_at IS NOT NULL)
              {$isoSql}
            GROUP BY j.adm_level, j.iso_code
            ORDER BY j.adm_level, j.iso_code
            ",
            $bindings
        );

        $findings = [];
        foreach ($rows as $row) {
            $iso = $row->iso_code ?? '??';
            $findings[] = [
                'severity'         => 'critical',
                'jurisdiction_id'  => null, // group flag — no single anchor row
                'title'            => "{$row->cnt} orphaned row(s) at ADM{$row->adm_level} ({$iso})",
                'suggested_action' => 'reparent',
                // The COUNT rides in the fingerprint: an accepted group flag
                // must stay sticky only while the orphan population is
                // unchanged — new orphans at the same (iso, level) mint a new
                // fingerprint and a fresh open critical flag.
                'fingerprint'      => $this->fingerprint('orphaned_rows', [$iso], $row->adm_level . '|' . $row->cnt),
                'payload'          => [
                    'iso'          => $row->iso_code,
                    'adm_level'    => (int) $row->adm_level,
                    'count'        => (int) $row->cnt,
                    'sample_slugs' => json_decode($row->sample_slugs, true) ?: [],
                ],
            ];
        }

        return $findings;
    }

    // ─── Scan mechanics ──────────────────────────────────────────────────────

    /**
     * Hard-delete a category's open flags (derived artifacts). When the scan
     * is iso-scoped, only that scope's open flags are cleared — every payload
     * carries an `iso` key (clusters also `parent_iso`) precisely for this.
     */
    private function deleteOpenFlags(string $category, ?array $isoCodes): void
    {
        $query = DB::table('geodata_flags')
            ->where('category', $category)
            ->where('status', 'open');

        if ($isoCodes !== null) {
            $arr = $this->pgTextArray($isoCodes);
            $query->whereRaw(
                "(payload->>'iso' = ANY(CAST(? AS text[])) OR payload->>'parent_iso' = ANY(CAST(? AS text[])) OR payload->>'host_iso' = ANY(CAST(? AS text[])))",
                [$arr, $arr, $arr]
            );
        }

        $query->delete();
    }

    /**
     * Insert findings as open flags, skipping any whose fingerprint already
     * exists as an accepted/resolved flag (operator state wins across
     * rescans). Batched — same_space_chain alone can produce ~13k rows.
     *
     * @param  list<array>  $findings
     * @return int  number of open flags inserted
     */
    private function insertFlags(string $category, array $findings): int
    {
        if ($findings === []) {
            return 0;
        }

        // 'open' rides in the keep-set too: an iso-SCOPED rescan's delete
        // clause and a detector's own scope can disagree at the margins
        // (e.g. a cross-iso finding whose payload isos differ from the scan
        // filter) — without this, such a finding would insert a duplicate
        // open flag next to the surviving one.
        $keep = DB::table('geodata_flags')
            ->where('category', $category)
            ->whereIn('status', ['open', 'accepted', 'resolved'])
            ->whereNull('deleted_at')
            ->pluck('fingerprint')
            ->flip()
            ->all();

        $now  = now();
        $rows = [];
        $seen = [];
        foreach ($findings as $finding) {
            $fingerprint = $finding['fingerprint'];
            if (isset($keep[$fingerprint]) || isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;

            $rows[] = [
                'id'                      => (string) Str::uuid(),
                'category'                => $category,
                'severity'                => $finding['severity'],
                'jurisdiction_id'         => $finding['jurisdiction_id'] ?? null,
                'related_jurisdiction_id' => $finding['related_jurisdiction_id'] ?? null,
                'title'                   => mb_substr($finding['title'], 0, 255),
                'payload'                 => json_encode($finding['payload']),
                'fingerprint'             => $fingerprint,
                'suggested_action'        => $finding['suggested_action'] ?? null,
                'status'                  => 'open',
                'detected_at'             => $now,
                'created_at'              => $now,
                'updated_at'              => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('geodata_flags')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * fingerprint = sha1(category | sorted target slugs | discriminating fact)
     * — the stable identity an accepted flag keeps across rescans.
     *
     * @param  list<string>  $slugs
     */
    private function fingerprint(string $category, array $slugs, string $fact): string
    {
        sort($slugs);

        return sha1($category . '|' . implode(',', $slugs) . '|' . $fact);
    }

    /**
     * @return array{0:string,1:array}  [SQL fragment ('' when unfiltered), bindings]
     */
    private function isoClause(string $column, ?array $isoCodes, string $param): array
    {
        if ($isoCodes === null) {
            return ['', []];
        }

        return [
            "AND {$column} = ANY(CAST(:{$param} AS text[]))",
            [$param => $this->pgTextArray($isoCodes)],
        ];
    }

    /** @return list<string>|null upper-cased, de-duplicated; null when no filter */
    private function normalizeIsoCodes(?array $isoCodes): ?array
    {
        if ($isoCodes === null) {
            return null;
        }

        $normalized = array_values(array_unique(array_filter(
            array_map(fn ($iso) => strtoupper(trim((string) $iso)), $isoCodes),
            fn ($iso) => $iso !== ''
        )));

        return $normalized === [] ? null : $normalized;
    }

    /** PostgreSQL text[] literal, e.g. {"FRA","MTQ"} (safe for CAST(? AS text[])). */
    private function pgTextArray(array $values): string
    {
        $quoted = array_map(
            fn ($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"',
            $values
        );

        return '{' . implode(',', $quoted) . '}';
    }
}
