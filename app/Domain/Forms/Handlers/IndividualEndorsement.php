<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\RaceFootprint;
use App\Models\Candidacy;
use App\Models\Endorsement;
use App\Models\User;

/**
 * F-IND-025 — Individual Endorsement (R-04).
 *
 * A resident's own public act of support for a candidacy. Distinct from
 * two other things it is easy to confuse it with:
 *  - the SECRET approval vote (ApprovalService / approvals table), which is
 *    anonymous and writes no per-approval chain entry;
 *  - the ORGANIZATION endorsement handshake (F-CAN-002 → F-ORG-002), which
 *    is agent-granted, forced public, and the R-07 derivation source.
 * An individual endorsement confers R-07 on no one (R-07 derives from
 * organization endorsements only) and is DEFAULT PRIVATE — the endorser
 * opts in to the public record (my-record contract, D-3).
 *
 * The shared write primitive Endorsement::recordFor enforces nothing, so
 * this handler enforces every rule: the actor is a resident of the race
 * footprint (Art. I), the actor is not the candidate, and the candidacy
 * still stands. There is no election-phase window (operator ruling
 * 2026-09-13): an endorsement can be made, withdrawn and made again at any
 * time while the candidacy stands, in any election status. One logical row
 * is toggled — withdraw then re-endorse is the same row (D-2).
 */
class IndividualEndorsement implements FormHandler
{
    public function module(): string
    {
        return 'elections';
    }

    public function event(): string
    {
        return 'endorsement.filed';
    }

    public function requiredRoles(): array
    {
        return ['R-04'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'An individual endorsement is a resident\'s own act; it has no system-filed form.',
                'CGA Forms Catalog (F-IND-025)'
            );
        }

        $candidacy = Candidacy::query()->findOrFail($payload['candidacy_id'] ?? null);
        $actorId = (string) $actor->getKey();

        // A candidate cannot endorse their own candidacy.
        if ((string) $candidacy->user_id === $actorId) {
            throw new ConstitutionalViolation(
                'You cannot endorse your own candidacy.',
                'Art. II §2 · CGA Forms Catalog (F-IND-025)'
            );
        }

        IndividualEndorsement::assertStanding($candidacy);

        // Art. I: endorsing requires an active association inside the
        // candidacy's race footprint — the same gate the open ballot applies
        // to approvals (ApprovalController::assertFootprint).
        if ($candidacy->race_id === null) {
            throw new ConstitutionalViolation(
                'This candidacy is not yet bound to a race (awaiting board validation) — endorsements open once it is in the pool.',
                'Art. II §2 · CGA open-ballot spec'
            );
        }

        $inFootprint = RaceFootprint::bestRaceForUser(
            $actorId,
            (string) $candidacy->election_id,
            (string) $candidacy->race_id,
        ) !== null;

        if (! $inFootprint) {
            throw new ConstitutionalViolation(
                'Endorsing requires jurisdictional association in this race — you can browse, not endorse, here.',
                'Art. I'
            );
        }

        $isPublic = (bool) ($payload['is_public'] ?? false);

        $endorsement = Endorsement::recordFor($candidacy, Endorsement::ENDORSER_USER, $actorId, [
            'statement'    => isset($payload['statement']) ? (string) $payload['statement'] : null,
            'endorsed_at'  => now(),
            'withdrawn_at' => null,
            'is_active'    => true,
            'is_public'    => $isPublic,
        ]);

        return [
            'endorsement_id' => (string) $endorsement->id,
            'candidacy_id'   => (string) $candidacy->id,
            'election_id'    => (string) $candidacy->election_id,
            'is_public'      => $isPublic,
            'withdrawn'      => false,
        ];
    }

    /**
     * The standing gate shared by F-IND-025 and F-IND-026: the candidacy must
     * still stand. This is a STATE gate, not a time gate — there is no
     * election-phase window (operator ruling 2026-09-13). An endorsement can
     * be made, withdrawn and made again at any time while the candidacy
     * stands, in any election status.
     */
    public static function assertStanding(Candidacy $candidacy): void
    {
        $standing = in_array($candidacy->status, [
            Candidacy::STATUS_REGISTERED,
            Candidacy::STATUS_VALIDATED,
            Candidacy::STATUS_IN_POOL,
            Candidacy::STATUS_FINALIST,
        ], true);

        if (! $standing) {
            throw new ConstitutionalViolation(
                "Candidacy [{$candidacy->id}] is no longer standing (status: {$candidacy->status}).",
                'CGA Forms Catalog (F-IND-025)'
            );
        }
    }
}
