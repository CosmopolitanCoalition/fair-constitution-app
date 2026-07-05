<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\BoardProvenance;
use App\Models\User;
use App\Services\ConstitutionalDefaults;
use App\Services\Districting\PopulationRaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F-ELB-008 — Manual District Draw (R-08; design §4.3).
 *
 * The HUMAN counterpart to shortest-splitline (F-ELB-007): for a CHILDLESS LEAF
 * GIANT — a jurisdiction whose whole seat budget exceeds the resolved ceiling
 * but which has no child jurisdictions to compose from — an election-board
 * member draws an intra-jurisdiction district on the map. This handler persists
 * one committed hand-drawn shape as a `district_subdivisions` row (method
 * 'manual') + its `legislature_districts` row + a polymorphic membership row
 * (subdivision_id), inside a DRAFT plan. Activation stays F-ELB-003.
 *
 * Hard gates (all enforced before any write; a failure throws a
 * ConstitutionalViolation and the engine records a rejected audit edge):
 *   - the scope is genuinely a childless leaf giant (entitlement > resolved
 *     ceiling, no children) — manual draw is ONLY for this case (Art. II §8);
 *   - the drawn piece's rounded seats sit in the resolved [floor, ceiling] band
 *     (Art. II §2 / Art. V §3, amendable — never a literal 5/9);
 *   - the piece is a single contiguous polygon (Art. II §8);
 *   - the piece lies inside the giant's own boundary;
 *   - the piece does not overlap a sibling drawn piece already in the plan.
 *
 * Population is the AGGREGATE worldpop raster sum (population_within_multi) —
 * never raw locations (§5 P1). The giant's own clamp district (the one
 * `clamped_pending_subdivision_capability` stub seating it at the ceiling) is
 * superseded in this plan the first time a piece is drawn.
 */
class ManualDistrictDraw implements FormHandler
{
    public function __construct(
        private readonly PopulationRaster $raster,
    ) {}

    public function module(): string
    {
        return 'elections';
    }

    public function event(): string
    {
        return 'district.drawn';
    }

    public function requiredRoles(): array
    {
        return ['R-08'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $legislatureId = (string) ($payload['legislature_id'] ?? '');
        $scopeId = (string) ($payload['scope_id'] ?? '');
        $mapId = (string) ($payload['map_id'] ?? '');
        $geoJson = $payload['geojson'] ?? null;
        $label = trim((string) ($payload['label'] ?? ''));
        $year = (int) ($payload['population_year'] ?? 2023);

        if (is_array($geoJson)) {
            $geoJson = json_encode($geoJson);
        }
        if (! is_string($geoJson) || $geoJson === '') {
            throw new ConstitutionalViolation(
                'F-ELB-008 requires a drawn polygon (geojson).',
                'CGA Forms Catalog (F-ELB-008)'
            );
        }

        $leg = DB::table('legislatures')->where('id', $legislatureId)->whereNull('deleted_at')->first();
        if ($leg === null) {
            throw new ConstitutionalViolation('F-ELB-008 targets an unknown legislature.', 'CGA Forms Catalog (F-ELB-008)');
        }

        $map = DB::table('legislature_district_maps')->where('id', $mapId)->whereNull('deleted_at')->first();
        if ($map === null || $map->legislature_id !== $leg->id) {
            throw new ConstitutionalViolation('F-ELB-008 targets an unknown district map.', 'CGA Forms Catalog (F-ELB-008)');
        }
        if ($map->status !== 'draft') {
            // SETUP-context exception, same posture as the provenance skip below:
            // during Initial Setup the FOUNDING map IS the active map — there is no
            // standing government to run the draft → approval → vote procedure
            // (that begins at map v2), so the founder/system draws v1 directly.
            // Governed context keeps drafts-only.
            $activeFoundingMap = $map->status === 'active'
                && BoardProvenance::inSetupContext((string) $leg->jurisdiction_id);
            if (! $activeFoundingMap) {
                throw new ConstitutionalViolation(
                    "District map [{$map->id}] is not a draft (status: {$map->status}) — "
                    .'a standing government drafts new plans and votes them active.',
                    'CGA Forms Catalog (F-ELB-008)'
                );
            }
        }

        // An operator-chosen label must be unique per plan among LIVE rows (the
        // partial unique index) — refuse a duplicate up front, with the other
        // cheap input gates, so the filing fails with a citation before any
        // raster work and never as a raw 23505 out of the database.
        if ($label !== '') {
            $labelTaken = DB::table('district_subdivisions')
                ->where('map_id', $mapId)
                ->where('label', $label)
                ->whereNull('deleted_at')
                ->exists();
            if ($labelTaken) {
                throw new ConstitutionalViolation(
                    "A drawn district named \"{$label}\" already exists in this plan — choose another label.",
                    'CGA Forms Catalog (F-ELB-008)'
                );
            }
        }

        // SCOPE (R-08): a HUMAN board member may only draw districts for a legislature whose jurisdiction's
        // board they sit on — the role gate proves a seat on SOME board, board-blind. The system path (null
        // actor) bypasses (the engine bypasses the role gate for a system filing).
        //
        // SETUP-context exception (Art. II bootstrap posture — operator ruling, map v1/map v2):
        // the FOUNDING map is drawn during Initial Setup, before any government exists, so it
        // carries no election-board requirement — the institution that would hold provenance
        // has not been stood up yet (the bootstrap board's synthetic user_id-NULL member is
        // the system, not a government). While the legislature's jurisdiction has no
        // human-seated active board, the OPERATOR (the founder building the world) files
        // without a seat. The first human seated on an active board ends the context — map
        // version 2 onward is drafted by the standing government, and own-seat provenance
        // binds exactly as before. Non-operator humans get no exception in any context: they
        // fail the R-08 role gate or the own-seat resolution below, citation intact.
        if ($actor !== null && ! BoardProvenance::isFounderInSetupContext($actor, (string) $leg->jurisdiction_id)) {
            $board = BoardProvenance::boardForJurisdiction((string) $leg->jurisdiction_id, 'F-ELB-008');
            BoardProvenance::resolveMemberOnBoard($actor, $board, 'F-ELB-008');
        }

        $giant = DB::table('jurisdictions')->where('id', $scopeId)->whereNull('deleted_at')->first();
        if ($giant === null || $giant->geom === null) {
            throw new ConstitutionalViolation('F-ELB-008 targets an unknown jurisdiction.', 'CGA Forms Catalog (F-ELB-008)');
        }

        $floor = ConstitutionalDefaults::floor($leg->jurisdiction_id);
        $ceiling = ConstitutionalDefaults::ceiling($leg->jurisdiction_id);
        $giantThreshold = ConstitutionalDefaults::giantThreshold($leg->jurisdiction_id);

        // The scope must be a CHILDLESS LEAF GIANT — manual draw exists for no
        // other case (composite districts every child-bearing scope).
        $rootPop = max((int) DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('population'), 1);
        $totalSeats = max((int) $leg->type_a_seats, 1);
        $giantPop = (int) $giant->population;
        $giantFrac = $giantPop * $totalSeats / $rootPop;

        $childCount = (int) DB::table('jurisdictions')
            ->where('parent_id', $scopeId)->whereNull('deleted_at')->count();

        if ($giantFrac < $giantThreshold || $childCount > 0) {
            throw new ConstitutionalViolation(
                "{$giant->name} is not a childless leaf giant (fractional seats "
                .number_format($giantFrac, 2).' vs threshold '.number_format($giantThreshold, 1)
                .", {$childCount} children) — manual line-drawing applies only to the case "
                .'composite cannot: a giant with no children.',
                'Art. II §8'
            );
        }

        // Whole seat budget the giant divides into (the LegislatureController clamp:
        // round(fractional_seats)) and the local quota for the pieces.
        $S = max($floor, (int) round($giantFrac));
        $quota = $giantPop / max($S, 1);

        // Geometry validation in one round-trip: contiguity (single part), within
        // the giant, and the piece's geometry / centroid as GeoJSON for persistence.
        $geo = DB::selectOne(
            'WITH d AS (
                 SELECT ST_MakeValid(ST_GeomFromGeoJSON(?)) AS g
             ),
             gi AS (
                 SELECT ST_MakeValid(geom) AS g FROM jurisdictions WHERE id = ?
             )
             SELECT
                 ST_NumGeometries(ST_Multi((SELECT g FROM d)))              AS parts,
                 ST_CoveredBy((SELECT g FROM d), (SELECT g FROM gi))        AS within,
                 ST_IsEmpty((SELECT g FROM d))                              AS empty',
            [$geoJson, $scopeId]
        );

        if ($geo === null || (bool) $geo->empty) {
            throw new ConstitutionalViolation('The drawn polygon is empty or invalid.', 'Art. II §2');
        }
        if ((int) $geo->parts !== 1) {
            throw new ConstitutionalViolation(
                "A district must be a single contiguous polygon (drawn shape has {$geo->parts} parts).",
                'Art. II §8'
            );
        }
        if (! (bool) $geo->within) {
            throw new ConstitutionalViolation(
                "The drawn district extends outside {$giant->name}'s boundary.",
                'Art. II §8'
            );
        }

        // Aggregate population inside the drawn piece, and its seat entitlement.
        $pop = $this->raster->populationWithinMulti($geoJson, $year);
        $fractional = $this->raster->impliedSeats($pop, $quota);
        $seats = (int) round($fractional);

        if ($seats < $floor || $seats > $ceiling) {
            throw new ConstitutionalViolation(
                sprintf(
                    'The drawn district holds %s people (%.2f of the local quota %.1f) — %d seats, '
                    .'outside the resolved band [%d, %d]. Redraw it larger or smaller.',
                    number_format($pop), $fractional, $quota, $seats, $floor, $ceiling
                ),
                'Art. II §2'
            );
        }

        // No overlap with a sibling piece already drawn in this plan.
        $overlap = DB::selectOne(
            'SELECT count(*) AS n
               FROM district_subdivisions ds
              WHERE ds.map_id = ?
                AND ds.parent_jurisdiction_id = ?
                AND ds.deleted_at IS NULL
                AND ST_Overlaps(ds.geom, ST_Multi(ST_MakeValid(ST_GeomFromGeoJSON(?))))',
            [$mapId, $scopeId, $geoJson]
        );
        if ((int) ($overlap->n ?? 0) > 0) {
            throw new ConstitutionalViolation(
                'The drawn district overlaps a district already drawn in this plan — voter pools may not overlap.',
                'Art. II §8'
            );
        }

        // ── Persist ──────────────────────────────────────────────────────────
        // Supersede the giant's clamp district in THIS plan the first time a
        // piece is drawn (the stub that seats the whole giant at the ceiling).
        $clampDistrictIds = DB::table('legislature_districts AS ld')
            ->join('legislature_district_jurisdictions AS ldj', 'ldj.district_id', '=', 'ld.id')
            ->where('ld.map_id', $mapId)
            ->where('ld.jurisdiction_id', $scopeId)
            ->where('ldj.jurisdiction_id', $scopeId)
            ->whereNull('ld.deleted_at')
            ->pluck('ld.id');
        if ($clampDistrictIds->isNotEmpty()) {
            DB::table('legislature_districts')->whereIn('id', $clampDistrictIds)->update(['deleted_at' => now()]);
        }

        $nextNumber = (int) DB::table('legislature_districts')
            ->where('legislature_id', $legislatureId)
            ->where('map_id', $mapId)
            ->where('jurisdiction_id', $scopeId)
            ->whereNull('deleted_at')
            ->max('district_number');
        $nextNumber++;

        if ($label === '') {
            // Auto-label numbering is collision-proof by construction: the live
            // district_number sequence resets when districts are cleared, but a
            // label may survive as a soft-deleted ghost (or a live row from an
            // operator's own naming) — so start from the HIGHEST number any
            // label on this map+scope has ever carried (soft-deleted rows
            // included; DB::table applies no soft-delete scope), then bump
            // until the label is free among live rows. A 23505 out of the
            // partial unique index must be unreachable from this path.
            $labelBase = "{$giant->name} — drawn district ";
            $maxLabelNumber = (int) DB::table('district_subdivisions')
                ->where('map_id', $mapId)
                ->where('parent_jurisdiction_id', $scopeId)
                ->where('label', 'like', $labelBase.'%')
                ->selectRaw("MAX(NULLIF(substring(label from '[0-9]+$'), '')::int) AS n")
                ->value('n');

            $n = max($nextNumber, $maxLabelNumber + 1);
            $label = $labelBase.$n;
            while (DB::table('district_subdivisions')
                ->where('map_id', $mapId)
                ->where('label', $label)
                ->whereNull('deleted_at')
                ->exists()) {
                $label = $labelBase.(++$n);
            }
        }

        $subdivisionId = (string) Str::uuid();
        DB::statement(
            "INSERT INTO district_subdivisions
                (id, map_id, parent_jurisdiction_id, method, label, geom, centroid,
                 population, population_source, population_year, fractional_seats, seats, status,
                 created_at, updated_at)
             VALUES
                (?, ?, ?, 'manual', ?,
                 ST_Multi(ST_MakeValid(ST_GeomFromGeoJSON(?))),
                 ST_Centroid(ST_MakeValid(ST_GeomFromGeoJSON(?))),
                 ?, 'worldpop_raster', ?, ?, ?, 'draft', now(), now())",
            [$subdivisionId, $mapId, $scopeId, $label, $geoJson, $geoJson, $pop, $year, $fractional, $seats]
        );

        $districtId = (string) Str::uuid();
        DB::table('legislature_districts')->insert([
            'id' => $districtId,
            'legislature_id' => $legislatureId,
            'map_id' => $mapId,
            'jurisdiction_id' => $scopeId,
            'district_number' => $nextNumber,
            'seats' => $seats,
            'target_population' => $pop,
            'actual_population' => $pop,
            'fractional_seats' => round($fractional, 6),
            'floor_override' => $seats < $floor,
            'status' => 'active',
            'num_geom_parts' => 1,
            'is_contiguous' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('legislature_district_jurisdictions')->insert([
            'id' => (string) Str::uuid(),
            'district_id' => $districtId,
            'subdivision_id' => $subdivisionId,
        ]);

        // Convex-hull ratio for the map-quality panel, straight from the drawn geom.
        DB::statement(
            'UPDATE legislature_districts ld SET convex_hull_ratio = (
                 SELECT ST_Area(ds.geom) / NULLIF(ST_Area(ST_ConvexHull(ds.geom)), 0)
                   FROM district_subdivisions ds WHERE ds.id = ?
             ) WHERE ld.id = ?',
            [$subdivisionId, $districtId]
        );

        return [
            'legislature_id' => $legislatureId,
            'jurisdiction_id' => $scopeId,
            'map_id' => $mapId,
            'subdivision_id' => $subdivisionId,
            'district_id' => $districtId,
            'district_number' => $nextNumber,
            'seats' => $seats,
            'population' => $pop,
            'fractional_seats' => round($fractional, 6),
            'giant_seat_budget' => $S,
            'clamp_superseded' => $clampDistrictIds->isNotEmpty(),
        ];
    }
}
