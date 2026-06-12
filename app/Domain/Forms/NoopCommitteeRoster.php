<?php

namespace App\Domain\Forms;

use App\Domain\Forms\Contracts\CommitteeRoster;

/**
 * Bound until the chamber-ops committees substrate lands (committee
 * tables + CommitteeAssignmentService). Knows no committees: committee
 * votes cannot open and F-LEG-005 casts cannot authorize — honestly,
 * with a clear failure, instead of pretending a roster exists.
 * Same pattern as NoopBallotBoxDelegate (Phase B).
 */
class NoopCommitteeRoster implements CommitteeRoster
{
    public function laneCounts(string $committeeId): array
    {
        return ['legislature_id' => null, 'jurisdiction_id' => null, 'lanes' => []];
    }

    public function isMember(string $committeeId, string $memberId): bool
    {
        return false;
    }

    public function laneOf(string $committeeId, string $memberId): ?string
    {
        return null;
    }
}
