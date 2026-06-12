<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\BoardProvenance;
use App\Models\Petition;
use App\Models\User;
use App\Services\PetitionService;

/**
 * F-ELB-005 — Petition Signature Audit (R-08; the Phase B deferral lands
 * here — no handler existed before this batch).
 *
 * The board responsible is the ACTIVE board of the petition's
 * jurisdiction (the bootstrap board's system-actor path works exactly as
 * in Phase B: actor null → the synthetic user_id-NULL member row).
 *
 * PetitionService::runSignatureAudit verifies, per unrevoked signature:
 * (a) point-in-time association coverage at signed_at, (b) no duplicate
 * signers, (c) the signature predates the audit. valid ≥ threshold_count
 * → the petition HOLDS at constitutional_review (Phase E F-JDG-008);
 * else → invalidated (kill-path 1, Art. II §6 independent audit). The
 * full result jsonb rides this filing's chain entry and the published
 * record.
 */
class PetitionSignatureAudit implements FormHandler
{
    public function __construct(private readonly PetitionService $petitions)
    {
    }

    public function module(): string
    {
        return 'elections';
    }

    public function event(): string
    {
        return 'petition.audited';
    }

    public function requiredRoles(): array
    {
        return ['R-08'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $petition = Petition::query()->find($payload['petition_id'] ?? null);

        if ($petition === null) {
            throw new ConstitutionalViolation(
                'F-ELB-005 targets an unknown petition.',
                'CGA Forms Catalog (F-ELB-005)'
            );
        }

        // Provenance: the audit must come from the jurisdiction's board.
        $board  = BoardProvenance::boardForJurisdiction((string) $petition->jurisdiction_id, 'F-ELB-005');
        $member = BoardProvenance::resolveMemberOnBoard($actor, $board, 'F-ELB-005');

        $result = $this->petitions->runSignatureAudit($petition);

        return [
            'petition_id'     => (string) $petition->id,
            'jurisdiction_id' => (string) $petition->jurisdiction_id,
            'board_id'        => (string) $board->id,
            'board_member_id' => (string) $member->id,
            'audit_result'    => $result,
            'status'          => $petition->refresh()->status,
        ];
    }
}
