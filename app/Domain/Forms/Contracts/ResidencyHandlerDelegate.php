<?php

namespace App\Domain\Forms\Contracts;

use App\Models\User;

/**
 * Seam between the residency form handlers (F-IND-003 / F-IND-005 /
 * F-IND-006) and the real residency machinery (ResidencyService, WI-5:
 * residency_claims state machine, location ping inserts, association
 * sweep + ping purge on verification).
 *
 * WI-2 bound NoopResidencyDelegate; WI-5 rebinds ResidencyService in
 * ConstitutionProvider without touching the handlers or the engine.
 *
 * All methods run inside the engine's DB transaction and return extra
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

    /**
     * F-IND-006 — system-filed verification confirmation: transition the
     * claim verified→active, sweep the full ancestor chain (plus
     * dual-footprint twins) into residency confirmations, and purge the
     * claim's raw pings (privacy: coordinates never outlive verification).
     *
     * @return array extra audit payload (MUST include the association
     *               jurisdiction-id list; never raw coordinates)
     */
    public function confirmVerification(?User $actor, array $payload): array;
}
