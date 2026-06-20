<?php

namespace App\Services\Matrix;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\MatrixCarveoutLog;
use App\Models\OperatorAccount;
use App\Models\StandingAttestation;
use App\Services\ConstitutionalValidator;
use Throwable;

/**
 * Phase K-3 (K3-I.3) — the Plane-B (Matrix) arm of a carve-out removal. A PUBLIC room is otherwise
 * uncensorable (Art. I); the ONLY removals are the four carve-outs, and this is the single emitter that
 * turns a validated, flip-authorised carve-out into an m.room.redaction. Two gates, both reused so a
 * Matrix removal is exactly as constrained as a public-square removal:
 *
 *   (1) the SHAPE gate — ConstitutionalValidator::check('F-SOC-003'): a named carve-out (judicial order
 *       or rights protection) + a justifying reference; viewpoint / discretionary removal is
 *       structurally unrepresentable.
 *   (2) the AUTHORITY gate — ModerationFlipService::resolve: WHO may invoke (operator-relay in bootstrap
 *       vs. live R-19/R-20 judicial attestation once seated) + the action class.
 *
 * Only when BOTH pass does it redact. The redaction is BEST-EFFORT (the homeserver being down never
 * fails the constitutional act — the durable artifact is the log, which the mesh discontinuity detector
 * reads), and logFlip() seals matrix_carveout_log + public_records('moderation_flip') in one transaction.
 * M-3 per-user block has NO path here — it is the resident's own client-side ignore list.
 */
class CarveoutEmitterService
{
    /** F-SOC-003 carve-out vocabulary → the matrix_carveout_log carve_out + flip key. */
    private const CARVE_MAP = [
        'judicial_order'    => 'm1_judicial',
        'rights_protection' => 'm2_rights',
    ];

    public function __construct(
        private readonly ConstitutionalValidator $validator,
        private readonly ModerationFlipService $flip,
        private readonly MatrixClientService $client,
    ) {}

    /**
     * Emit a carve-out removal of a single Matrix event.
     *
     * @param  string  $carveOut  F-SOC-003 vocabulary: judicial_order | rights_protection
     */
    public function emit(
        string $jurisdictionId,
        string $roomId,
        string $eventId,
        string $carveOut,
        string $reference,
        ?StandingAttestation $attestation = null,
        ?OperatorAccount $operator = null,
    ): MatrixCarveoutLog {
        // (1) the SHAPE gate — a Matrix removal is as constrained as a square removal.
        $this->validator->check('F-SOC-003', ['carve_out' => $carveOut, 'reference' => $reference]);

        $key = self::CARVE_MAP[$carveOut] ?? null;
        if ($key === null) {
            throw new ConstitutionalViolation('Unknown Matrix carve-out class.', 'Art. I');
        }

        // (2) the AUTHORITY gate — the legitimacy flip decides WHO + the action class. Fails closed.
        $decision = $this->flip->resolve($jurisdictionId, $key, $attestation, $operator);
        if (! $decision->permitted) {
            throw new ConstitutionalViolation($decision->reason ?? 'Carve-out refused.', 'Art. I');
        }

        return $this->sealThenRedact($decision, $roomId, $eventId, sprintf('[%s] %s', $key, $reference));
    }

    /**
     * M-4 anti-spam — content-neutral, behaviour-based suppression. No carve-out reference, no judicial
     * attestation: the operator (bootstrap) or seated legislature owns the knobs. Always a soft-fail.
     */
    public function emitAntispam(
        string $jurisdictionId,
        string $roomId,
        string $eventId,
        ?OperatorAccount $operator = null,
    ): MatrixCarveoutLog {
        $decision = $this->flip->resolve($jurisdictionId, 'm4_antispam', null, $operator);

        // Defence in depth: m4 currently always resolves permitted, but never redact on a refusal if a
        // future condition (e.g. seated-legislature-owned knobs) makes it refusable — fail closed.
        if (! $decision->permitted) {
            throw new ConstitutionalViolation($decision->reason ?? 'Anti-spam action refused.', 'Art. II §3');
        }

        return $this->sealThenRedact($decision, $roomId, $eventId, '[m4_antispam] content-neutral rate-limit');
    }

    /**
     * Seal FIRST (the durable, appealable artifact — the F-SOC-003 "log first, then remove" discipline),
     * THEN best-effort redact. A down homeserver leaves the bytes live but never voids the sealed log.
     */
    private function sealThenRedact(FlipDecision $decision, string $roomId, string $eventId, string $reason): MatrixCarveoutLog
    {
        $log = $this->flip->logFlip($decision, $roomId, $eventId);

        try {
            $this->client->redact($roomId, $eventId, $reason);
        } catch (Throwable $e) {
            // Best-effort UI removal (Art. I §5.7 — never "erased"). The seal already landed; the bytes'
            // disappearance is best-effort across the mesh, the LOG is the durable constitutional artifact.
        }

        return $log;
    }
}
