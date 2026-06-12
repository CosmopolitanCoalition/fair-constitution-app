<?php

namespace App\Domain\Forms\Handlers\Concerns;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\LegislatureMember;
use App\Models\User;

/**
 * Shared by the Phase C legislative handlers: resolve the filing actor's
 * CURRENT member row in a given legislature (R-09 derives from exactly
 * this fact — RoleService::hasCurrentLegislatureSeat).
 */
trait ResolvesLegislativeActor
{
    /**
     * @throws ConstitutionalViolation when the actor holds no current seat there
     */
    protected function currentMemberOf(?User $actor, string $legislatureId): LegislatureMember
    {
        $member = $actor === null ? null : LegislatureMember::query()
            ->where('legislature_id', $legislatureId)
            ->where('user_id', (string) $actor->getKey())
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->first();

        if ($member === null) {
            throw new ConstitutionalViolation(
                'The filer holds no current seat in this legislature.',
                'Art. II §2'
            );
        }

        return $member;
    }
}
