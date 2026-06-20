<?php

namespace App\Services\Matrix;

use App\Models\FederationPeer;
use App\Models\Legislature;
use App\Models\MatrixCarveoutLog;
use App\Models\OperatorAccount;
use App\Models\StandingAttestation;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\AttestationService;
use App\Services\PublicRecordService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase K-3 (K3-I.2) — the legitimacy-gated moderation FLIP. The four carve-outs are the ONLY removals
 * from an otherwise-uncensorable public room (Art. I); WHO may invoke them is not a stored "moderator"
 * bit but a function of LOCAL SEATEDNESS, derived live and flipped automatically:
 *
 *   • BELOW the flip (no seated government) — there is no judiciary. M-2 rights-protection is relayed by
 *     the operator board (R-08), NEUTRAL + logged; M-1 judicial is UNAVAILABLE (there is no judge to
 *     order it).
 *   • AT/ABOVE the flip (an active legislature governs the jurisdiction — the SAME derived fact as G-VER
 *     Meter A→B and G6 LocalAutonomyService::open) — the appservice REQUIRES a live R-19/R-20
 *     StandingAttestation for M-1/M-2 and STOPS honoring the operator. Binary, automatic, not seizable.
 *
 * The flip is an AUTHORITY decision only: it NEVER moves a Matrix power level (the v12 appservice creator
 * stays the sole holder — this service has no Matrix client at all, so a power-level mutation is
 * structurally impossible). M-3 per-user block is client-side (m.ignored_user_list) and is NEVER an
 * appservice action. Every PERMITTED carve-out is sealed by logFlip() to BOTH the machine audit
 * (matrix_carveout_log; attestation_id discriminates a real judicial order from an operator relay — the
 * anti-forgery + mesh "censorship-without-an-order" detector) and the citizen register
 * (public_records 'moderation_flip').
 */
class ModerationFlipService
{
    public function __construct(
        private readonly AttestationService $attestations,
        private readonly InstanceIdentityService $identity,
        private readonly PublicRecordService $records,
    ) {}

    /** The flip key: is $jurisdictionId governed by a SEATED government (an active legislature)? */
    public function isSeated(string $jurisdictionId): bool
    {
        return Legislature::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('status', Legislature::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Resolve carve-out authority under the CURRENT flip — FAILS CLOSED. Pure (no writes, no Matrix):
     * the caller redacts + logs only when the decision is permitted.
     *
     * @param  string  $carveOut  m1_judicial | m2_rights | m3_block | m4_antispam
     */
    public function resolve(
        string $jurisdictionId,
        string $carveOut,
        ?StandingAttestation $attestation = null,
        ?OperatorAccount $operator = null,
    ): FlipDecision {
        $seated = $this->isSeated($jurisdictionId);

        // M-3 is NEVER an appservice action — it is each resident's own client-side m.ignored_user_list.
        if ($carveOut === 'm3_block') {
            return FlipDecision::refused(
                $jurisdictionId, $carveOut, $seated, 'client_side',
                "A per-user block is the resident's own client-side ignore list — never an appservice removal."
            );
        }

        // M-4 anti-spam — content-neutral system knobs (rate-limit / soft-fail), permitted on EITHER side
        // of the flip. WHO owns the knobs flips operator → legislature, but no per-event judicial
        // attestation is required (it is behaviour-based, never viewpoint). attestation_id stays NULL.
        if ($carveOut === 'm4_antispam') {
            return FlipDecision::permitted(
                $jurisdictionId, $carveOut, $seated, 'system_antispam',
                MatrixCarveoutLog::ACTION_SOFT_FAIL, null, $operator
            );
        }

        if ($seated) {
            // POST-FLIP: only a live, valid R-19/R-20 judicial attestation. The operator is no longer
            // honoured. The attestation is verified against ITS CLAIMED ISSUER's pinned key (self → our
            // key; a peer → federation_peers.public_key), never blindly against our own — an attestation
            // from an unknown / un-pinned issuer FAILS CLOSED. verifyAttestation additionally fails closed
            // on expiry/revocation/forgery; the roles snapshot must actually carry the judicial office.
            $issuerKey = $attestation !== null ? $this->issuerKeyFor($attestation) : null;
            if ($attestation === null
                || $issuerKey === null
                || ! $this->attestations->verifyAttestation($attestation, $issuerKey)
                || ! $this->hasJudicialRole($attestation)) {
                return FlipDecision::refused(
                    $jurisdictionId, $carveOut, $seated, 'refused',
                    'A seated jurisdiction admits a carve-out ONLY under a live R-19/R-20 judicial attestation — '
                    .'the operator board is no longer honoured.'
                );
            }

            // M-2 rights protection strips content (hard); a reversible judicial order is a soft-fail.
            $action = $carveOut === 'm2_rights'
                ? MatrixCarveoutLog::ACTION_HARD_REDACT
                : MatrixCarveoutLog::ACTION_SOFT_FAIL;

            return FlipDecision::permitted(
                $jurisdictionId, $carveOut, $seated, 'judicial_attested', $action, $attestation, null
            );
        }

        // PRE-FLIP (bootstrap): no judiciary exists, so M-1 cannot be ordered.
        if ($carveOut === 'm1_judicial') {
            return FlipDecision::refused(
                $jurisdictionId, $carveOut, $seated, 'unavailable_no_judge',
                'M-1 judicial removal is unavailable before a government is seated — there is no judge to order it.'
            );
        }

        // M-2 rights — the operator board (R-08) relays, NEUTRAL + logged. attestation_id stays NULL so a
        // relay can never be mistaken for a judicial order.
        if ($carveOut === 'm2_rights') {
            if ($operator === null || ! $operator->isActive()) {
                return FlipDecision::refused(
                    $jurisdictionId, $carveOut, $seated, 'refused',
                    'A bootstrap rights-protection removal must be relayed by an ACTIVE operator board (R-08).'
                );
            }

            return FlipDecision::permitted(
                $jurisdictionId, $carveOut, $seated, 'operator_relay',
                MatrixCarveoutLog::ACTION_HARD_REDACT, null, $operator
            );
        }

        return FlipDecision::refused($jurisdictionId, $carveOut, $seated, 'refused', 'Unknown carve-out.');
    }

    /**
     * Seal a PERMITTED carve-out to BOTH logs in one transaction: the citizen-readable
     * public_records('moderation_flip') and the machine matrix_carveout_log. attestation_id is set ONLY
     * for a judicial order — an operator relay / anti-spam action leaves it NULL.
     */
    public function logFlip(FlipDecision $d, string $roomId, ?string $eventId = null): MatrixCarveoutLog
    {
        if (! $d->permitted) {
            throw new LogicException('logFlip records only a PERMITTED carve-out — a refusal is never an action.');
        }

        return DB::transaction(function () use ($d, $roomId, $eventId): MatrixCarveoutLog {
            $record = $this->records->publish(
                kind: 'moderation_flip',
                title: sprintf('Carve-out [%s] exercised (%s)', $d->carveOut, $d->basis),
                body: sprintf(
                    'A %s carve-out was exercised under authority basis [%s]; the jurisdiction was %s at the time.',
                    $d->carveOut, $d->basis, $d->isSeated ? 'seated' : 'in bootstrap (no seated government)'
                ),
                attrs: [
                    'jurisdiction_id' => $d->jurisdictionId,
                    // The Matrix coordinates live in matrix_carveout_log, never a public_records subject FK.
                ],
            );

            return MatrixCarveoutLog::create([
                'matrix_room_id'    => $roomId,
                'matrix_event_id'   => $eventId,
                'carve_out'         => $d->carveOut,
                'action'            => $d->action,
                'attestation_id'    => $d->attestationId,
                'issuer_server_id'  => $d->issuerServerId,
                'public_records_id' => $record->id,
                'jurisdiction_id'   => $d->jurisdictionId,
                'is_seated_at_time' => $d->isSeated,
            ]);
        });
    }

    private function hasJudicialRole(StandingAttestation $attestation): bool
    {
        $roles = (array) $attestation->roles;

        return in_array('R-19', $roles, true) || in_array('R-20', $roles, true);
    }

    /**
     * The PINNED public key of the attestation's CLAIMED issuer (the established serverKey pattern): self
     * → our own key; a known peer → its pinned federation_peers.public_key. Null when the claimed issuer
     * is unknown or carries no pinned key — the caller treats null as a refusal (fail closed): we never
     * verify a stranger's signature against our own key.
     */
    private function issuerKeyFor(StandingAttestation $attestation): ?string
    {
        $issuer = (string) $attestation->issuer_server_id;

        if ($issuer !== '' && $issuer === $this->identity->serverId()) {
            return $this->identity->publicKey();
        }

        $peer = FederationPeer::query()->where('server_id', $issuer)->first();

        return $peer?->public_key !== null ? (string) $peer->public_key : null;
    }
}

