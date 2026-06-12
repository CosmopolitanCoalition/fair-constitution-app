<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\LegislatureDistrictMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WI-B7 — initial district map generation at activation (master-plan
 * backlog #3, pulled forward; design PHASE_B_DESIGN_schema_lifecycle §B.4
 * San Marino row).
 *
 * A chamber whose type_a_seats exceed the constitutional ceiling (9)
 * cannot be elected at-large (Art. II §8 — subdivision is MANDATORY
 * above the max). When the jurisdiction has constituent children, this
 * service makes the chamber electable at bootstrap time:
 *
 *  1. create a draft `legislature_district_maps` row;
 *  2. run the landed DistrictingService auto-composite at the
 *     legislature's root scope (Webster over the constituent
 *     jurisdictions → 5–9-seat composite districts);
 *  3. THE LEAF-GIANT CLAMP: a constituent whose fractional seats exceed
 *     the giant threshold is skipped by the composite (giants are meant
 *     to be subdivided at the next level) — but a CHILDLESS giant has
 *     nothing to subdivide over. Same Art. II §8 posture as the
 *     Montegiardino chamber clamp: it becomes a single district clamped
 *     to the ceiling, audited with citation; the shortest-split-line
 *     drawing tool (master-plan backlog #1) lifts this later.
 *  4. reconcile: seats freed by the clamp are re-placed by Webster
 *     priority (pop / (2s+1)) so the map's seat vector sums EXACTLY to
 *     type_a_seats with every district inside [floor, ceiling];
 *  5. system-file F-ELB-003 through the ConstitutionalEngine — the
 *     landed SubdivisionBoundaryDrawing handler validates the 5–9 band +
 *     membership shape and flips the draft → active (archiving any
 *     prior active plan). Board provenance: the bootstrap board is
 *     constituted by ActivationService step 3.5 BEFORE this runs; the
 *     null-actor filing is the system acting as that board.
 *
 * A giant constituent that HAS children (the Earth case — China, India)
 * is out of scope for bootstrap generation: generation aborts and the
 * election stays in the §B.4 blocked-pending-subdivision posture.
 */
class InitialDistrictMapService
{
    public function __construct(
        private readonly DistrictingService $districting,
        private readonly AuditService $audit,
        private readonly ConstitutionalEngine $engine,
    ) {
    }

    /**
     * Ensure the legislature has an ACTIVE district map when one is
     * constitutionally required (type_a_seats > ceiling AND constituent
     * children exist). Idempotent: an existing active map is adopted.
     *
     * @param  object  $legislature  full legislatures row (stdClass)
     * @return array{map_id: string, district_count: int, seat_vector: list<int>, generated: bool}|null
     *         null when no map is required (seats ≤ ceiling, or leaf).
     *
     * @throws RuntimeException when generation cannot produce a
     *         constitutional map (caller records the blocked posture).
     */
    public function ensureInitialMap(object $legislature, string $jurisdictionId): ?array
    {
        $ceiling = ConstitutionalDefaults::ceiling($jurisdictionId);
        $typeA   = (int) $legislature->type_a_seats;

        if ($typeA <= $ceiling) {
            return null; // at-large is the constitutional default (§B.4)
        }

        $childCount = (int) DB::table('jurisdictions')
            ->where('parent_id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->whereNotNull('geom')
            ->count();

        if ($childCount === 0) {
            return null; // undistrictable leaf — the chamber clamp path owns this
        }

        // Idempotent: an active plan is adopted, never regenerated.
        $active = LegislatureDistrictMap::query()
            ->where('legislature_id', $legislature->id)
            ->active()
            ->first();

        if ($active !== null) {
            return $this->summarize((string) $active->id) + ['generated' => false];
        }

        $map = $this->createDraftMap($legislature);

        $clamps = DB::transaction(function () use ($legislature, $jurisdictionId, $typeA, $map) {
            $result = $this->districting->runAutoCompositeForScope(
                (string) $legislature->id,
                $legislature,
                $jurisdictionId,
                true,            // fresh map — clear is scoped to this map_id (no-op) but skips stale-assignment filters
                $typeA,
                (string) $map->id,
            );

            if ($result['error'] !== null) {
                throw new RuntimeException("Initial-map auto-composite failed: {$result['error']}");
            }

            $clamps = $this->clampUnassignedLeafGiants($legislature, $jurisdictionId, $typeA, (string) $map->id);

            $this->reconcileSeatTotal($legislature, $jurisdictionId, $typeA, (string) $map->id);

            return $clamps;
        });

        $summary = $this->summarize((string) $map->id);

        $this->audit->append(
            module: 'elections',
            event: 'district_map.generated',
            payload: [
                'map_id'         => (string) $map->id,
                'legislature_id' => (string) $legislature->id,
                'type_a_seats'   => $typeA,
                'district_count' => $summary['district_count'],
                'seat_vector'    => $summary['seat_vector'],
                'generator'      => 'DistrictingService auto-composite (bootstrap initial map, backlog #3)',
                'leaf_giant_clamps' => $clamps,
            ],
            ref: 'WF-ELE-02',
            jurisdictionId: $jurisdictionId,
        );

        // F-ELB-003 — draft → active through the engine (system files as
        // the bootstrap board; the handler validates 5–9 + membership and
        // seals its own chain entry).
        $this->engine->file('F-ELB-003', null, [
            'map_id'          => (string) $map->id,
            'jurisdiction_id' => $jurisdictionId,
        ]);

        return $this->summarize((string) $map->id) + ['generated' => true];
    }

    // -------------------------------------------------------------------------

    private function createDraftMap(object $legislature): LegislatureDistrictMap
    {
        $base = 'Initial Map (bootstrap)';
        $name = $base;
        $n    = 1;

        while (LegislatureDistrictMap::query()
            ->where('legislature_id', $legislature->id)
            ->where('name', $name)
            ->exists()) {
            $name = $base . ' ' . (++$n);
        }

        return LegislatureDistrictMap::create([
            'id'             => (string) Str::uuid(),
            'legislature_id' => (string) $legislature->id,
            'name'           => $name,
            'description'    => 'Auto-generated at activation (WI-B7 bootstrap — Art. II §8 mandatory subdivision).',
            'status'         => LegislatureDistrictMap::STATUS_DRAFT,
        ]);
    }

    /**
     * Direct children of the scope left unassigned by the composite are
     * its GIANTS (fractional seats ≥ ceiling + 0.5). A childless giant
     * cannot be subdivided — it becomes a single district clamped to the
     * ceiling (Art. II §8 posture, lifted later by the shortest-split-line
     * tool). A giant WITH children aborts bootstrap generation.
     *
     * @return list<array{jurisdiction_id: string, name: string, fractional_seats: float, clamped_to: int, citation: string, note: string}>
     */
    private function clampUnassignedLeafGiants(object $legislature, string $scopeId, int $typeA, string $mapId): array
    {
        $unassigned = DB::select("
            SELECT j.id, j.name, j.population,
                   (SELECT count(*) FROM jurisdictions c
                     WHERE c.parent_id = j.id AND c.deleted_at IS NULL) AS child_count
              FROM jurisdictions j
             WHERE j.parent_id = ?
               AND j.deleted_at IS NULL
               AND j.geom IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1
                     FROM legislature_district_jurisdictions ldj
                     JOIN legislature_districts d ON d.id = ldj.district_id
                    WHERE ldj.jurisdiction_id = j.id
                      AND d.map_id = ?
                      AND d.deleted_at IS NULL
               )
             ORDER BY j.population DESC
        ", [$scopeId, $mapId]);

        if ($unassigned === []) {
            return [];
        }

        $ceiling = ConstitutionalDefaults::ceiling($scopeId);

        $totalChildPop = (int) DB::table('jurisdictions')
            ->where('parent_id', $scopeId)
            ->whereNull('deleted_at')
            ->whereNotNull('geom')
            ->sum('population');
        $quota = $totalChildPop / max($typeA, 1);

        $clamps = [];

        foreach ($unassigned as $giant) {
            if ((int) $giant->child_count > 0) {
                throw new RuntimeException(
                    "Constituent {$giant->name} exceeds the giant threshold and has its own children — "
                    . 'bootstrap initial-map generation does not recurse (Earth-class chamber; '
                    . 'stays blocked pending subdivision, Art. II §8).'
                );
            }

            $fractional = ((int) $giant->population) / max($quota, 1);
            $seats      = min($ceiling, max(ConstitutionalDefaults::floor($scopeId), (int) round($fractional)));

            $districtNumber = 1 + (int) DB::table('legislature_districts')
                ->where('legislature_id', $legislature->id)
                ->where('jurisdiction_id', $scopeId)
                ->where('map_id', $mapId)
                ->whereNull('deleted_at')
                ->max('district_number');

            $districtId = (string) Str::uuid();

            DB::table('legislature_districts')->insert([
                'id'                => $districtId,
                'legislature_id'    => (string) $legislature->id,
                'map_id'            => $mapId,
                'jurisdiction_id'   => $scopeId,
                'district_number'   => $districtNumber,
                'seats'             => $seats,
                'fractional_seats'  => round($fractional, 6),
                'floor_override'    => false,
                'target_population' => (int) $giant->population,
                'actual_population' => (int) $giant->population,
                'status'            => 'active',
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            DB::table('legislature_district_jurisdictions')->insert([
                'id'              => (string) Str::uuid(),
                'district_id'     => $districtId,
                'jurisdiction_id' => (string) $giant->id,
            ]);

            // Spatial stats only — seats stay as clamped (Webster skip).
            $this->districting->recomputeDistrict($districtId, (string) $legislature->id, $legislature, true);

            $clamps[] = [
                'jurisdiction_id'  => (string) $giant->id,
                'name'             => (string) $giant->name,
                'fractional_seats' => round($fractional, 4),
                'clamped_to'       => $seats,
                'citation'         => 'Art. II §2; Art. II §8',
                'note'             => 'clamped_pending_subdivision_capability — childless constituent above the '
                    . 'giant threshold; the shortest-split-line drawing tool (backlog #1) restores '
                    . 'proportional sizing with intra-jurisdiction districts later.',
            ];
        }

        return $clamps;
    }

    /**
     * Make the map's seat vector sum EXACTLY to type_a_seats. The
     * leaf-giant clamp frees seats (locked round(frac) ≥ ceiling+1 →
     * clamped ceiling); freed seats are re-placed one at a time by
     * Webster priority pop/(2s+1) across districts below the ceiling —
     * the same rule the composite itself uses, so the completed map is
     * the Webster apportionment of type_a_seats under the ceiling bound.
     */
    private function reconcileSeatTotal(object $legislature, string $scopeId, int $typeA, string $mapId): void
    {
        $floor   = ConstitutionalDefaults::floor($scopeId);
        $ceiling = ConstitutionalDefaults::ceiling($scopeId);

        $districts = DB::table('legislature_districts')
            ->where('map_id', $mapId)
            ->whereNull('deleted_at')
            ->get(['id', 'seats', 'actual_population'])
            ->map(fn ($d) => (object) [
                'id'    => (string) $d->id,
                'seats' => (int) $d->seats,
                'pop'   => (int) ($d->actual_population ?? 0),
            ])
            ->all();

        $sum = array_sum(array_map(fn ($d) => $d->seats, $districts));

        while ($sum < $typeA) {
            $best = null;
            foreach ($districts as $d) {
                if ($d->seats >= $ceiling) {
                    continue;
                }
                $priority = $d->pop / (2 * $d->seats + 1);
                if ($best === null || $priority > $best->priority) {
                    $best = (object) ['district' => $d, 'priority' => $priority];
                }
            }
            if ($best === null) {
                throw new RuntimeException(
                    "Cannot place {$typeA} seats: every district is at the constitutional ceiling ({$ceiling})."
                );
            }
            $best->district->seats++;
            $sum++;
        }

        while ($sum > $typeA) {
            $worst = null;
            foreach ($districts as $d) {
                if ($d->seats <= $floor) {
                    continue;
                }
                $priority = $d->pop / (2 * $d->seats - 1); // priority of the seat being removed
                if ($worst === null || $priority < $worst->priority) {
                    $worst = (object) ['district' => $d, 'priority' => $priority];
                }
            }
            if ($worst === null) {
                throw new RuntimeException(
                    "Cannot trim to {$typeA} seats: every district is at the constitutional floor ({$floor})."
                );
            }
            $worst->district->seats--;
            $sum--;
        }

        foreach ($districts as $d) {
            DB::table('legislature_districts')
                ->where('id', $d->id)
                ->update(['seats' => $d->seats, 'updated_at' => now()]);
        }
    }

    /** @return array{map_id: string, district_count: int, seat_vector: list<int>} */
    private function summarize(string $mapId): array
    {
        $seats = DB::table('legislature_districts')
            ->where('map_id', $mapId)
            ->whereNull('deleted_at')
            ->orderByDesc('seats')
            ->pluck('seats')
            ->map(fn ($s) => (int) $s)
            ->all();

        return [
            'map_id'         => $mapId,
            'district_count' => count($seats),
            'seat_vector'    => $seats,
        ];
    }
}
