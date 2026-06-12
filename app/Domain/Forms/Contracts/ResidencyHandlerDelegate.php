<?php

namespace App\Domain\Forms\Contracts;

use App\Models\User;

/**
 * Seam between the Phase A residency form handlers (F-IND-003 / F-IND-005)
 * and the real residency machinery that lands in WI-5 (ResidencyService:
 * residency_claims state machine, location ping inserts, CLK-05 arming).
 *
 * Phase A binds NoopResidencyDelegate; WI-5 rebinds in
 * ConstitutionProvider without touching the handlers or the engine.
 *
 * Both methods run inside the engine's DB transaction and return extra
 * key/value pairs merged into the audit payload (never raw coordinates).
 */
interface ResidencyHandlerDelegate
{
    /**
     * F-IND-003 — create/advance the residency claim for the declared
     * jurisdiction.
     *
     * @return array extra audit payload (e.g. ['claim_id' => ...])
     */
    public function declare(?User $actor, array $payload): array;

    /**
     * F-IND-005 — persist a location ping against the actor's monitored
     * claim. Coordinates stay private; return value must not echo them.
     *
     * @return array extra audit payload (e.g. ['qualifying_days' => ...])
     */
    public function recordPing(?User $actor, array $payload): array;
}
