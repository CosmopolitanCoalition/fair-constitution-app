<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Candidacy;
use App\Models\Endorsement;
use App\Models\User;

/**
 * F-IND-026 — Individual Endorsement Withdrawal (R-04).
 *
 * The endorser retracts their OWN individual endorsement. Ownership is
 * structural: the handler only ever addresses the row keyed by the actor's
 * own id, so it cannot touch another resident's endorsement or an
 * organization endorsement. The same standing gate binds withdrawal — the
 * candidacy must still stand. There is no election-phase window (operator
 * ruling 2026-09-13): a withdrawal can be filed at any time while the
 * candidacy stands. Setting withdrawn_at drops the row from
 * Endorsement::scopeActive; the endorser may later re-endorse the SAME
 * logical row (D-2, F-IND-025 sets withdrawn_at null again).
 */
class IndividualEndorsementWithdrawal implements FormHandler
{
    public function module(): string
    {
        return 'elections';
    }

    public function event(): string
    {
        return 'endorsement.withdrawn';
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
                'An individual endorsement withdrawal is a resident\'s own act; it has no system-filed form.',
                'CGA Forms Catalog (F-IND-026)'
            );
        }

        $candidacy = Candidacy::query()->findOrFail($payload['candidacy_id'] ?? null);
        $actorId = (string) $actor->getKey();

        // The actor may only withdraw an endorsement they themselves hold and
        // that is still active. A resident with no active row (never endorsed,
        // or already withdrawn) has nothing to retract.
        $existing = Endorsement::query()->logicalType(Endorsement::ENDORSER_USER)
            ->where('endorsements.election_id', $candidacy->election_id)
            ->where('endorsements.candidate_id', $candidacy->id)
            ->where('endorsements.endorser_id', $actorId)
            ->where('endorsements.is_active', true)
            ->whereNull('endorsements.withdrawn_at')
            ->first();

        if ($existing === null) {
            throw new ConstitutionalViolation(
                'You have no active endorsement of this candidacy to withdraw.',
                'CGA Forms Catalog (F-IND-026)'
            );
        }

        IndividualEndorsement::assertStanding($candidacy);

        $endorsement = Endorsement::recordFor($candidacy, Endorsement::ENDORSER_USER, $actorId, [
            'withdrawn_at' => now(),
            'is_active'    => false,
        ]);

        return [
            'endorsement_id' => (string) $endorsement->id,
            'candidacy_id'   => (string) $candidacy->id,
            'election_id'    => (string) $candidacy->election_id,
            'withdrawn'      => true,
        ];
    }
}
