<?php

namespace App\Services\Jurisdictions;

use App\Models\LocalAutonomyProcess;
use App\Models\OperationalPartitionExport;

/**
 * The result of a governed earned-autonomy flip (G6).
 *
 * Carries the finalized process AND — when the flipping subtree holds sealed
 * elections and the gaining cluster is a pinned co-member — the G5 sealed
 * operational bundle: each subtree election's per-election key k_e, SEALED to the
 * gaining cluster's key (libsodium sealed box; only it can open it). The bundle is
 * what the transport delivers to the gaining side's
 * `POST /api/federation/flip/operational` endpoint, where G5a re-wraps each key
 * under the gaining cluster's own KEK, fail-closed.
 *
 * The sealed blob is NEVER persisted in the clear; only its fingerprint + the
 * OUTBOUND `OperationalPartitionExport` ledger row are durable. `sealedOperationalBundle`
 * is null when the subtree has no sealed elections, or when the gaining peer is not
 * yet a pinned co-member (in which case the deferral is audited so it is not lost).
 */
final class AutonomyFlipResult
{
    public function __construct(
        public readonly LocalAutonomyProcess $process,
        public readonly ?string $sealedOperationalBundle = null,
        public readonly ?OperationalPartitionExport $bundleExport = null,
    ) {}
}
