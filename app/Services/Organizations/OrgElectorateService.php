<?php

namespace App\Services\Organizations;

use App\Models\Board;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\Organization;
use App\Models\OrgMembership;
use App\Models\OrgOwnershipStake;
use App\Models\OrgWorker;

/**
 * D-O5 (PHASE_D_DESIGN_organizations §C.1) — electorate resolution for
 * org-board races.
 *
 * DECISION (binding, §C.1): one-member-one-vote within the electorate
 * CLASS. Stakes define who stands on the owner side and the economics —
 * NEVER vote weight (the PROTECTED VoteCountingService counts one ballot
 * per envelope; weighting would fork the protected path). Recorded as a
 * q-ledger candidate.
 *
 *  - 'owners'  → users holding an active org_memberships row of the org's
 *    ownership class, plus user-type holders of open ownership stakes
 *    (the service keeps these consistent).
 *  - 'workers' → users with an active org_workers row for the employer.
 *
 * Org races are Art. III §6 board structure, NOT Art. I public office:
 * the class check is the single permissible ground
 * ('no_class_membership', mirroring 'no_residency_association') and
 * residency/identity conditions remain forbidden on org races.
 */
class OrgElectorateService
{
    /** Whether a user belongs to a race's org electorate. */
    public function isEligible(string $userId, ElectionRace $race): bool
    {
        $board = $this->boardForRace($race);

        if ($board === null) {
            return false;
        }

        return match ($race->electorate_type) {
            ElectionRace::ELECTORATE_OWNERS  => $this->isOwner($userId, $board),
            ElectionRace::ELECTORATE_WORKERS => $this->isWorker($userId, $board),
            default                          => false,
        };
    }

    /** Owner-class membership (per structure) OR an open user-held stake. */
    public function isOwner(string $userId, Board $board): bool
    {
        $org = $board->organization();

        if ($org === null) {
            return false;
        }

        $class = $org->membershipKind();

        $viaMembership = $class !== null && OrgMembership::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $userId)
            ->where('kind', $class)
            ->active()
            ->exists();

        if ($viaMembership) {
            return true;
        }

        return OrgOwnershipStake::query()
            ->where('organization_id', $org->id)
            ->where('holder_type', OrgOwnershipStake::HOLDER_USERS)
            ->where('holder_id', $userId)
            ->open()
            ->exists();
    }

    /** Active F-IND-014 worker row for the board's employer entity. */
    public function isWorker(string $userId, Board $board): bool
    {
        return OrgWorker::query()
            ->forEmployer((string) $board->boardable_type, (string) $board->boardable_id)
            ->where('user_id', $userId)
            ->active()
            ->exists();
    }

    public function boardForRace(ElectionRace $race): ?Board
    {
        $boardId = Election::query()->whereKey($race->election_id)->value('board_id');

        return $boardId !== null ? Board::query()->find($boardId) : null;
    }
}
