<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Petition;
use App\Models\User;
use App\Services\PetitionService;

/**
 * F-IND-010 — Petition Signature (R-03). Signing AND revocation ride the
 * same form (`revoke: true` withdraws the live signature) — both are
 * civic participation, both chain.
 *
 * The ONLY gate is an active association with the petition's jurisdiction
 * (Art. I). Signatures are revocable while the petition gathers; each
 * insert runs the event-driven CLK-17 threshold check (the sweep job is
 * the safety net). Signatures are PUBLIC participation — unlike ballots,
 * a petition signature is an open declaration (the audit verifies it).
 */
class PetitionSignature implements FormHandler
{
    public function __construct(private readonly PetitionService $petitions)
    {
    }

    public function module(): string
    {
        return 'civic';
    }

    public function event(): string
    {
        return 'petition.signed';
    }

    public function requiredRoles(): array
    {
        return ['R-03'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'A petition is signed by a resident — system filing is not defined.',
                'Art. I'
            );
        }

        $petition = Petition::query()->find($payload['petition_id'] ?? null);

        if ($petition === null) {
            throw new ConstitutionalViolation(
                'F-IND-010 targets an unknown petition.',
                'CGA Forms Catalog (F-IND-010)'
            );
        }

        $revoke = (bool) ($payload['revoke'] ?? false);

        $signature = $revoke
            ? $this->petitions->revoke($petition, $actor)
            : $this->petitions->sign($petition, $actor);

        $petition->refresh();

        return [
            'petition_id'     => (string) $petition->id,
            'signature_id'    => (string) $signature->id,
            'action'          => $revoke ? 'revoked' : 'signed',
            'jurisdiction_id' => (string) $petition->jurisdiction_id,
            'live_signatures' => $petition->liveSignatureCount(),
            'threshold_count' => (int) $petition->threshold_count,
            'status'          => $petition->status,
        ];
    }
}
