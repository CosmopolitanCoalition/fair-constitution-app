<?php

namespace App\Services\Matrix;

use App\Models\OperatorAccount;
use App\Models\StandingAttestation;

/**
 * Phase K-3 (K3-I.2) — the immutable outcome of a moderation-flip authority resolution. Says WHETHER a
 * carve-out may be exercised right now, under WHAT legitimacy basis, and carries the discriminating ids
 * (a judicial attestation vs. an operator relay) that logFlip() seals. Never touches Matrix.
 *
 * basis vocabulary: judicial_attested | operator_relay | system_antispam | unavailable_no_judge |
 * client_side | refused.
 */
final class FlipDecision
{
    public function __construct(
        public readonly string $jurisdictionId,
        public readonly string $carveOut,
        public readonly bool $isSeated,
        public readonly bool $permitted,
        public readonly string $basis,
        public readonly ?string $action,
        public readonly ?string $attestationId,
        public readonly ?string $issuerServerId,
        public readonly ?string $operatorAccountId,
        public readonly ?string $reason,
    ) {}

    public static function permitted(
        string $jurisdictionId,
        string $carveOut,
        bool $seated,
        string $basis,
        string $action,
        ?StandingAttestation $attestation = null,
        ?OperatorAccount $operator = null,
    ): self {
        return new self(
            $jurisdictionId,
            $carveOut,
            $seated,
            true,
            $basis,
            $action,
            $attestation !== null ? (string) $attestation->id : null,
            $attestation?->issuer_server_id !== null ? (string) $attestation->issuer_server_id : null,
            $operator !== null ? (string) $operator->getKey() : null,
            null,
        );
    }

    public static function refused(
        string $jurisdictionId,
        string $carveOut,
        bool $seated,
        string $basis,
        string $reason,
    ): self {
        return new self($jurisdictionId, $carveOut, $seated, false, $basis, null, null, null, null, $reason);
    }
}
