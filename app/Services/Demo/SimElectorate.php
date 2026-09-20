<?php

namespace App\Services\Demo;

use App\Models\Election;
use App\Support\HostCapacity;
use Illuminate\Support\Facades\DB;

/** Integer turnout for synthetic cohorts; no more voters than residents. */
final class SimElectorate
{
    public static function size(int $population, int $turnoutPct): int
    {
        $population = max(0, $population);
        $turnoutPct = max(0, min(100, $turnoutPct));
        if ($population === 0 || $turnoutPct === 0) { return 0; }
        // Match the district simulation's existing minimum whole-person
        // rounding. Positive turnout in a one-person place means one ballot.
        return min($population, max(1, (int) floor($population * $turnoutPct / 100)));
    }

    public static function fromCohort(object $cohort): int
    {
        // Existing positive aggregates and completed counts are unchanged.
        return (int) $cohort->electorate === 0
            && (int) floor((int) $cohort->population * (int) $cohort->turnout_pct / 100) === 0
            ? self::size((int) $cohort->population, (int) $cohort->turnout_pct)
            : (int) $cohort->electorate;
    }

    /** Cheap scoped evidence for an explicit retry, not a planet census. */
    public static function hasTinyPanel(Election $election): bool
    {
        foreach ($election->races()->whereNotNull('type_b_panel_id')->get(['type_b_panel_id','seats']) as $race) {
            $members = DB::table('legislature_type_b_panel_jurisdictions')->where('panel_id', $race->type_b_panel_id)->pluck('jurisdiction_id');
            $people = 0; $known = 0; $rounded = false;
            foreach ($members->chunk(HostCapacity::sweepChunk()) as $ids) {
                $rows = DB::table('jurisdiction_cohorts as jc')->whereIn('jc.jurisdiction_id', $ids)
                    ->whereRaw('jc.version = (SELECT MAX(v.version) FROM jurisdiction_cohorts v WHERE v.jurisdiction_id = jc.jurisdiction_id)')
                    ->get(['jc.population','jc.electorate','jc.turnout_pct']);
                foreach ($rows as $row) {
                    $known++; $people += (int) $row->population;
                    $rounded = $rounded || self::fromCohort($row) > (int) $row->electorate;
                }
            }
            if ($known === $members->count() && $people > 0 && ($rounded || $people <= (int) $race->seats + 1)) { return true; }
        }
        return false;
    }
}
