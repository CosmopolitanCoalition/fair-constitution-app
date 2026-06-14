<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * THE population denominator for every population-pegged threshold
 * (petition CLK-17, referendum majority/supermajority, Phase F union /
 * border votes) — flagged decision, PHASE_C_DESIGN_votes_laws §A C-8:
 *
 *   civic population = count of ACTIVE residency_confirmations rows for
 *   the jurisdiction — never WorldPop `jurisdictions.population`.
 *
 * Precedents: owner ruling #15 (activation pegs PLAYER population);
 * the union-formation contract ("denominator = whole population, never
 * just voters" — the whole CIVIC population is the largest knowable
 * electorate); peg-quorum parallelism (denominator = all who COULD vote;
 * absent = no). Real population stays provenance data. Pinned by
 * ReferendumShieldTest / the petition threshold math.
 */
final class CivicPopulation
{
    private function __construct() {}

    public static function of(string $jurisdictionId): int
    {
        return (int) DB::table('residency_confirmations')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->count();
    }

    /**
     * Population of an AFFECTED AREA (Phase F border settlement, Art. V §2): the
     * count of DISTINCT active residents associated with the affected
     * sub-jurisdictions ONLY — never the whole of either bordering jurisdiction.
     * Each resident is counted once even when associated with several of them.
     *
     * @param  list<string>  $affectedJurisdictionIds
     */
    public static function forArea(array $affectedJurisdictionIds): int
    {
        if ($affectedJurisdictionIds === []) {
            return 0;
        }

        return (int) DB::table('residency_confirmations')
            ->whereIn('jurisdiction_id', $affectedJurisdictionIds)
            ->where('is_active', true)
            ->distinct()
            ->count('user_id');
    }
}
