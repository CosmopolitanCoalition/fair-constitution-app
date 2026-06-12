<?php

namespace App\Domain\Forms\Support;

use App\Models\ElectionRace;
use Illuminate\Support\Facades\DB;

/**
 * Race-footprint resolution shared by F-ELB-002 (candidate validation
 * binds race_id), F-IND-007 (ballot electorate check) and F-IND-011
 * (office-in-association-chain check).
 *
 * A race's footprint (design §A B-4):
 *  - district race  → the district's member jurisdictions
 *                     (legislature_district_jurisdictions rows)
 *  - at-large race  → the race's own jurisdiction_id (the legislature's
 *                     jurisdiction)
 *
 * A user is "inside the footprint" when they hold an ACTIVE
 * residency_confirmations row on any footprint jurisdiction. Because the
 * F-IND-006 verification sweep writes one confirmation per enclosing
 * jurisdiction (declared boundary at depth 0 → every ancestor), a resident
 * of any jurisdiction nested inside a footprint member necessarily holds a
 * confirmation row on the member itself — no recursive descent needed.
 */
class RaceFootprint
{
    /**
     * The best race binding for a user across an election's races:
     * matched by the user's DEEPEST active confirmation (lowest depth =
     * most specific), district races preferred over at-large on ties
     * (design §A B-5). Returns null when no race footprint contains an
     * active association of the user.
     *
     * @return object{race_id: string, district_id: string|null, jurisdiction_id: string, depth: int|null}|null
     */
    public static function bestRaceForUser(string $userId, string $electionId, ?string $onlyRaceId = null): ?object
    {
        $bindings = [$userId, $electionId];
        $raceFilter = '';

        if ($onlyRaceId !== null) {
            $raceFilter = 'AND er.id = ?';
            $bindings[] = $onlyRaceId;
        }

        return DB::selectOne(
            "SELECT er.id AS race_id,
                    er.district_id,
                    rc.jurisdiction_id,
                    rc.depth
             FROM election_races er
             LEFT JOIN legislature_district_jurisdictions ldj
                    ON ldj.district_id = er.district_id
             JOIN residency_confirmations rc
                    ON rc.user_id = ?
                   AND rc.is_active = true
                   AND rc.jurisdiction_id = COALESCE(ldj.jurisdiction_id, er.jurisdiction_id)
             WHERE er.election_id = ?
               AND er.deleted_at IS NULL
               {$raceFilter}
             ORDER BY rc.depth ASC NULLS LAST, (er.district_id IS NULL) ASC
             LIMIT 1",
            $bindings
        );
    }

    /** Whether the user holds an active association inside ONE race's footprint. */
    public static function userInFootprint(string $userId, ElectionRace $race): bool
    {
        return self::bestRaceForUser($userId, (string) $race->election_id, (string) $race->id) !== null;
    }
}
