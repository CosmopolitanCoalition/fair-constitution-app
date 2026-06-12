<?php

namespace App\Jobs;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\LegislatureMember;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WF-CIV-03 (chamber ops §F.2) — officeholder relocation → vacancy.
 *
 * Dispatched by ResidencyService::confirmVerification() when a verifying
 * claim supersedes a prior ACTIVE claim (associations transferred — the
 * constitutional grace IS the new jurisdiction's CLK-05 threshold: rights
 * never gap because the old claim stays Active until the new claim
 * verifies; the seat vacates only at actual re-association).
 *
 * Footprint test per current seat:
 *  - districted seat → the district's member jurisdictions
 *    (legislature_district_jurisdictions) ∩ the user's NEW active
 *    associations;
 *  - at-large / type_b seat → the legislature's own jurisdiction ∈
 *    associations.
 * Out of footprint → system-files F-LEG-036 (reason 'relocation') → the
 * full Phase B countback/special loop. Federation notification is a
 * Phase F stub (audit entry rides the F-LEG-036 chain).
 *
 * Away-pattern detection (sustained pings outside without re-declaration)
 * is DEFERRED to Phase F mobile geofencing — Phase C has manual/simulated
 * pings only; this event-driven hook is the honest Phase C trigger.
 */
class HandleOfficeholderRelocationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $userId,
    ) {
    }

    /**
     * Pure footprint predicate (pinned DB-free by RelocationVacancyTest):
     * a seat is out of footprint when none of its footprint jurisdictions
     * appears among the member's active associations.
     *
     * @param  list<string>  $footprintJurisdictionIds
     * @param  list<string>  $activeAssociationIds
     */
    public static function outOfFootprint(array $footprintJurisdictionIds, array $activeAssociationIds): bool
    {
        return array_intersect($footprintJurisdictionIds, $activeAssociationIds) === [];
    }

    public function handle(ConstitutionalEngine $engine): void
    {
        $associations = DB::table('residency_confirmations')
            ->where('user_id', $this->userId)
            ->where('is_active', true)
            ->pluck('jurisdiction_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $seats = LegislatureMember::query()
            ->where('user_id', $this->userId)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->get();

        foreach ($seats as $seat) {
            $footprint = $this->footprintFor($seat);

            if ($footprint === []) {
                Log::warning('Relocation check: seat has no resolvable footprint — skipped (honest gap).', [
                    'member_id' => (string) $seat->id,
                ]);

                continue;
            }

            if (! self::outOfFootprint($footprint, $associations)) {
                continue; // still represents where they live
            }

            $engine->file('F-LEG-036', null, [
                'member_id'       => (string) $seat->id,
                'reason'          => 'relocation',
                'jurisdiction_id' => (string) $seat->legislature()->value('jurisdiction_id'),
                'via_workflow'    => 'WF-CIV-03',
            ]);
        }
    }

    /** @return list<string> the seat's footprint jurisdiction ids */
    private function footprintFor(LegislatureMember $seat): array
    {
        if ($seat->district_id !== null) {
            return DB::table('legislature_district_jurisdictions')
                ->where('district_id', (string) $seat->district_id)
                ->pluck('jurisdiction_id')
                ->map(fn ($id) => (string) $id)
                ->all();
        }

        // At-large / type_b: the legislature's own jurisdiction.
        $jurisdictionId = $seat->legislature()->value('jurisdiction_id');

        return $jurisdictionId !== null ? [(string) $jurisdictionId] : [];
    }
}
