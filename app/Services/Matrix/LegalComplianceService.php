<?php

namespace App\Services\Matrix;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\LegalComplianceRemoval;
use App\Models\MatrixCarveoutLog;
use App\Models\OperatorAccount;
use Throwable;

/**
 * Phase K-3 (K3-I.4) — the operator-plane orchestrator of M-5, the PHYSICAL-LAW legal-compliance floor.
 * Removal of already-posted ILLEGAL material (CSAM, a specific court order, a true threat) — a different
 * axis from the four VIEWPOINT carve-outs, content-neutral, off the constitutional plane. Authenticated
 * by OperatorAccount key-possession (the controller verifies the device key; here we require an ACTIVE
 * account). It files F-SOC-004 (sealed: the immutable evidence trail + transparency record + the
 * m5_legal carve-out log, plus a disclosure referral when seated), THEN best-effort emits the Matrix
 * action: a CSAM hash-match PURGES the bytes (DELETE — quarantine keeps bytes), everything else redacts.
 *
 * Generalised hand-brake: this is the universal "physical hand-brake on reality" — content today, the
 * Phase-M market economy later. CODE-HARDENED: the basis set is a compiled enum grown only by code
 * release, never by an in-game act. World of Statecraft is an idealised world, but physical law and the
 * safety of operators take precedence; the byte-destruction + report obligations live on the operator
 * plane, the in-game justice runs its own response in parallel (M-5 flips to BOTH once seated).
 */
class LegalComplianceService
{
    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly MatrixClientService $client,
    ) {}

    /**
     * Remove already-posted illegal material under physical law. Per-item only.
     *
     * @param  string  $legalBasis  csam_hashmatch | court_order_specific | true_threat
     * @param  string|null  $matchedListSource  WHICH list matched (csam) — NEVER the hash itself
     * @return array the sealed F-SOC-004 record
     */
    public function remove(
        OperatorAccount $operator,
        string $matrixRoomId,
        string $matrixEventId,
        string $legalBasis,
        ?string $jurisdictionId = null,
        ?string $statutoryCitation = null,
        ?string $matchedListSource = null,
    ): array {
        if (! $operator->isActive()) {
            throw new ConstitutionalViolation(
                'Only an ACTIVE operator account may exercise the physical-law compliance floor.',
                'physical-law compliance floor'
            );
        }

        // ACTION_PURGE (byte-destroying) is reserved to a CSAM hash-match; a court order or true threat
        // uses reversible content redaction. The validator re-asserts this — defence in depth.
        $action = $legalBasis === LegalComplianceRemoval::BASIS_CSAM_HASHMATCH
            ? MatrixCarveoutLog::ACTION_PURGE
            : MatrixCarveoutLog::ACTION_HARD_REDACT;

        $result = $this->engine->file('F-SOC-004', null, [
            'operator_account_id' => (string) $operator->getKey(),
            'matrix_event_id'     => $matrixEventId,
            'matrix_room_id'      => $matrixRoomId,
            'legal_basis'         => $legalBasis,
            'action'              => $action,
            'statutory_citation'  => $statutoryCitation,
            'matched_list_source' => $matchedListSource,
            'jurisdiction_id'     => $jurisdictionId,
        ]);

        // Best-effort physical removal — the SEALED trail is the durable artifact; a down homeserver
        // never voids the constitutional act (and the legal PRESERVE→REPORT→PURGE sequence + NCMEC
        // submission live on the operator console with the operator's own credentials — rig-gated).
        try {
            if ($action === MatrixCarveoutLog::ACTION_PURGE) {
                $this->client->purgeEvent($matrixRoomId, $matrixEventId);   // DELETE the bytes (CSAM)
            } else {
                $this->client->redact($matrixRoomId, $matrixEventId, '[m5_legal] '.$legalBasis);
            }
        } catch (Throwable $e) {
            // ignore — the evidence trail + the operator's report obligation are the durable artifacts.
        }

        return $result->recorded;
    }
}
