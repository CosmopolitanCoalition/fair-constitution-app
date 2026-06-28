<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\BoardProvenance;
use App\Models\LegislatureDistrictMap;
use App\Models\User;
use App\Services\ConstitutionalValidator;
use Illuminate\Support\Facades\DB;

/**
 * F-ELB-003 — Subdivision Boundary Drawing (R-08).
 *
 * PHASE B MINIMAL (design §C): the manual drawing UI needs the raster
 * tool (worldpop_rasters + population_within()) — deferred. What ships
 * now is the ACTIVATION step: flip a draft district map → active and
 * archive the prior active map, validating the constitutional shape of
 * the plan first:
 *
 *  - every district seats 5–9 (Art. II §2, hardened band);
 *  - every district has ≥ 1 member jurisdiction;
 *  - no member jurisdiction sits in two districts of the same map;
 *  - every member jurisdiction lies inside the legislature's
 *    jurisdiction subtree.
 *
 * Full geometric coverage verification (no resident left outside every
 * district) is deferred with the raster tool — the auto-composite
 * generator (WI-B3 DistrictingService) builds maps from complete child
 * sets, so Phase B maps are covering by construction.
 */
class SubdivisionBoundaryDrawing implements FormHandler
{
    public function __construct(
        private readonly ConstitutionalValidator $validator,
    ) {}

    public function module(): string
    {
        return 'elections';
    }

    public function event(): string
    {
        return 'district_map.activated';
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
        $map = LegislatureDistrictMap::query()
            ->with('districts')
            ->find($payload['map_id'] ?? null);

        if ($map === null) {
            throw new ConstitutionalViolation(
                'F-ELB-003 targets an unknown district map.',
                'CGA Forms Catalog (F-ELB-003)'
            );
        }

        if ($map->status !== LegislatureDistrictMap::STATUS_DRAFT) {
            throw new ConstitutionalViolation(
                "District map [{$map->id}] is not a draft (status: {$map->status}).",
                'CGA Forms Catalog (F-ELB-003)'
            );
        }

        // SCOPE (R-08): a HUMAN board member may only activate the district map of a legislature whose
        // jurisdiction's board they sit on — the role gate proves a seat on SOME board, board-blind. The
        // system path (null actor — the auto-composite generator) bypasses, as the engine does for it.
        if ($actor !== null) {
            $legJurisdictionId = (string) DB::table('legislatures')
                ->where('id', (string) $map->legislature_id)->value('jurisdiction_id');
            $board = BoardProvenance::boardForJurisdiction($legJurisdictionId, 'F-ELB-003');
            BoardProvenance::resolveMemberOnBoard($actor, $board, 'F-ELB-003');
        }

        $districts = $map->districts;

        if ($districts->isEmpty()) {
            throw new ConstitutionalViolation(
                'A district map must contain at least one district.',
                'Art. II §8'
            );
        }

        $seatVector = [];

        foreach ($districts as $district) {
            // 5–9 per voter pool — hardened (Art. II §2).
            $this->validator->assertSeatsInRange((int) $district->seats);
            $seatVector[] = (int) $district->seats;
        }

        $this->assertMembership($map);

        // Archive the prior active plan; activate this one. History rows
        // survive (versioned plans — boundary changes never destroy
        // historical data).
        LegislatureDistrictMap::query()
            ->where('legislature_id', $map->legislature_id)
            ->where('status', LegislatureDistrictMap::STATUS_ACTIVE)
            ->whereKeyNot($map->id)
            ->update(['status' => LegislatureDistrictMap::STATUS_ARCHIVED, 'effective_end' => now()->toDateString()]);

        $map->forceFill([
            'status' => LegislatureDistrictMap::STATUS_ACTIVE,
            'effective_start' => now()->toDateString(),
        ])->save();

        return [
            'map_id' => (string) $map->id,
            'legislature_id' => (string) $map->legislature_id,
            'district_count' => $districts->count(),
            'seat_vector' => $seatVector,
        ];
    }

    /**
     * Membership shape: every district has members, no member appears
     * twice across the map, every member lies inside the legislature's
     * jurisdiction subtree.
     */
    private function assertMembership(LegislatureDistrictMap $map): void
    {
        $emptyDistricts = DB::table('legislature_districts as d')
            ->leftJoin('legislature_district_jurisdictions as ldj', 'ldj.district_id', '=', 'd.id')
            ->where('d.map_id', (string) $map->id)
            ->whereNull('d.deleted_at')
            ->whereNull('ldj.id')
            ->count();

        if ($emptyDistricts > 0) {
            throw new ConstitutionalViolation(
                "{$emptyDistricts} district(s) in the map have no member jurisdictions.",
                'Art. II §8'
            );
        }

        $duplicated = DB::table('legislature_district_jurisdictions as ldj')
            ->join('legislature_districts as d', 'd.id', '=', 'ldj.district_id')
            ->where('d.map_id', (string) $map->id)
            ->whereNull('d.deleted_at')
            ->select('ldj.jurisdiction_id')
            ->groupBy('ldj.jurisdiction_id')
            ->havingRaw('COUNT(DISTINCT ldj.district_id) > 1')
            ->get()
            ->count();

        if ($duplicated > 0) {
            throw new ConstitutionalViolation(
                "{$duplicated} jurisdiction(s) appear in more than one district of the map — voter pools may not overlap.",
                'Art. II §8'
            );
        }

        $legislatureJurisdictionId = DB::table('legislatures')
            ->where('id', (string) $map->legislature_id)
            ->value('jurisdiction_id');

        $outside = DB::selectOne(
            'WITH RECURSIVE subtree AS (
                SELECT id FROM jurisdictions WHERE id = ? AND deleted_at IS NULL
                UNION ALL
                SELECT j.id FROM jurisdictions j JOIN subtree s ON j.parent_id = s.id
                WHERE j.deleted_at IS NULL
            )
            SELECT COUNT(*) AS n
            FROM legislature_district_jurisdictions ldj
            JOIN legislature_districts d ON d.id = ldj.district_id AND d.map_id = ? AND d.deleted_at IS NULL
            WHERE ldj.jurisdiction_id NOT IN (SELECT id FROM subtree)',
            [$legislatureJurisdictionId, (string) $map->id]
        );

        if ((int) ($outside->n ?? 0) > 0) {
            throw new ConstitutionalViolation(
                "{$outside->n} member jurisdiction(s) lie outside the legislature's jurisdiction subtree.",
                'Art. II §8'
            );
        }
    }
}
