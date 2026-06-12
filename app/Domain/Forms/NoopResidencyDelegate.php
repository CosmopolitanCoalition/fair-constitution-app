<?php

namespace App\Domain\Forms;

use App\Domain\Forms\Contracts\ResidencyHandlerDelegate;
use App\Models\User;

/**
 * Default (Phase A) residency delegate: validation and audit-chaining of
 * F-IND-003 / F-IND-005 work end to end, but no residency_claims or
 * location_pings rows are written — that machinery arrives with WI-5,
 * which rebinds this interface in ConstitutionProvider.
 */
class NoopResidencyDelegate implements ResidencyHandlerDelegate
{
    public function declare(?User $actor, array $payload): array
    {
        return ['claim_created' => false, 'stub' => 'residency claim machinery lands in WI-5'];
    }

    public function recordPing(?User $actor, array $payload): array
    {
        return ['ping_persisted' => false, 'stub' => 'location ping machinery lands in WI-5'];
    }
}
