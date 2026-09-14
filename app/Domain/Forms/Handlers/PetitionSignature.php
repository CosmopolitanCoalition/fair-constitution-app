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

        // AC-1 achievement wiring (engine-transaction-coupled). The signer
        // earns VOX-001 for the act of signing (never on a revoke). When
        // this signature is the one that carries the petition to threshold,
        // the petition's CREATOR earns VOX-003 (EARNER_SUBJECT, resolved
        // from the petition row) — idempotent, so awarding on every
        // threshold-reached signature writes the creator's medal once.
        if ($actor !== null && ! $revoke) {
            app(\App\Services\AchievementService::class)->awardSelf($actor, 'ACH-VOX-001');
        }

        if (! $revoke && $petition->status === Petition::STATUS_THRESHOLD_REACHED) {
            $creator = User::find((string) $petition->creator_user_id);
            if ($creator !== null) {
                app(\App\Services\AchievementService::class)->awardSubject($creator, 'ACH-VOX-003');
            }
        }

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
