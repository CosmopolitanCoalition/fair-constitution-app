<?php

namespace App\Services\Jurisdictions;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\BorderSettlement;
use App\Models\JurisdictionMap;
use App\Services\AuditService;
use App\Services\ConstitutionalValidator;
use App\Support\CivicPopulation;
use Illuminate\Support\Facades\DB;

/**
 * Art. V §2 — border settlement. Two jurisdictions agree a shared boundary; it
 * is adopted only on a SUPERMAJORITY of the population IN THE AFFECTED AREA. The
 * denominator is CivicPopulation::forArea over the affected sub-jurisdictions
 * ONLY — never the whole civic population of either bordering jurisdiction. On
 * adoption a new jurisdiction_maps version records the boundary and the affected
 * residents are re-associated.
 */
class BorderSettlementService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param  list<string>  $affectedJurisdictionIds */
    public function open(string $jurisdictionA, string $jurisdictionB, array $affectedJurisdictionIds): BorderSettlement
    {
        if ($affectedJurisdictionIds === []) {
            throw new ConstitutionalViolation('A border settlement names the affected area.', 'Art. V §2');
        }

        $affectedPopulation = CivicPopulation::forArea($affectedJurisdictionIds);

        return DB::transaction(function () use ($jurisdictionA, $jurisdictionB, $affectedJurisdictionIds, $affectedPopulation) {
            $settlement = BorderSettlement::create([
                'jurisdiction_a_id' => $jurisdictionA,
                'jurisdiction_b_id' => $jurisdictionB,
                'affected_jurisdiction_ids' => array_values($affectedJurisdictionIds),
                'affected_population' => $affectedPopulation,
                'status' => BorderSettlement::STATUS_OPEN,
            ]);

            $this->audit->append('jurisdictions', 'border.opened', [
                'border_settlement_id' => (string) $settlement->id,
                'jurisdiction_a' => $jurisdictionA,
                'jurisdiction_b' => $jurisdictionB,
                'affected_population' => $affectedPopulation,
            ], 'WF-JUR-04', null, $jurisdictionA);

            return $settlement;
        });
    }

    /**
     * Record the affected-area referendum result. The threshold is a
     * supermajority of the AFFECTED-AREA population (not either whole jurisdiction).
     */
    public function recordReferendum(BorderSettlement $settlement, int $yesVotes): BorderSettlement
    {
        $required = ConstitutionalValidator::supermajority((int) $settlement->affected_population);
        $met = (int) $settlement->affected_population > 0 && $yesVotes >= $required;

        $settlement->forceFill(['affected_supermajority_met' => $met])->save();

        return $settlement->refresh();
    }

    /** Adopt the new boundary — only if the affected-area supermajority was met. */
    public function adopt(BorderSettlement $settlement): BorderSettlement
    {
        if (! $settlement->affected_supermajority_met) {
            $settlement->forceFill(['status' => BorderSettlement::STATUS_REJECTED])->save();
            throw new ConstitutionalViolation(
                'A boundary change is adopted only on a supermajority of the population in the AFFECTED AREA '
                .'(Art. V §2) — the denominator is the affected sub-jurisdictions, never the whole jurisdiction.',
                'Art. V §2'
            );
        }

        return DB::transaction(function () use ($settlement) {
            $rootId = (string) (DB::table('jurisdictions')->where('id', $settlement->jurisdiction_a_id)->value('parent_id')
                ?? $settlement->jurisdiction_a_id);

            $nextVersion = (int) JurisdictionMap::query()->where('root_jurisdiction_id', $rootId)->max('version_no') + 1;

            $map = JurisdictionMap::create([
                'root_jurisdiction_id' => $rootId,
                'name' => 'Border settlement '.$settlement->id,
                'status' => JurisdictionMap::STATUS_ACTIVE,
                'version_no' => $nextVersion,
                'origin' => 'border',
                'origin_process_id' => (string) $settlement->id,
                'effective_start' => now()->toDateString(),
            ]);

            // Re-association of affected residents (point-in-polygon sweep) rides
            // ResidencyService when precise geoms land; the map version + the
            // recorded affected set are the constitutional artefacts here.
            $settlement->forceFill([
                'status' => BorderSettlement::STATUS_ADOPTED,
                'jurisdiction_map_id' => (string) $map->id,
            ])->save();

            $this->audit->append('jurisdictions', 'border.adopted', [
                'border_settlement_id' => (string) $settlement->id,
                'jurisdiction_map_id' => (string) $map->id,
                'affected_population' => (int) $settlement->affected_population,
            ], 'WF-JUR-04', null, (string) $settlement->jurisdiction_a_id);

            return $settlement->refresh();
        });
    }
}
