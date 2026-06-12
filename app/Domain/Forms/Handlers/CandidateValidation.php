<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\BoardProvenance;
use App\Domain\Forms\Support\RaceFootprint;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\User;
use App\Services\RoleService;

/**
 * F-ELB-002 — Candidate Validation (R-08).
 *
 * Art. I — candidacy is an ABSOLUTE right of residency. The ONLY check
 * this handler is permitted to perform is: does the candidate hold an
 * active residency_confirmations row inside a race footprint of the
 * election? Three layers enforce it:
 *
 *  1. ConstitutionalValidator: F-ELB-002 is a RIGHTS_AUTOMATIC_FORMS
 *     member — payloads carrying eligibility keys are rejected pre-commit,
 *     and an explicit rejection_reason other than
 *     'no_residency_association' is rejected with citation Art. I.
 *  2. This handler: a 'reject' decision against a candidate who DOES hold
 *     residency inside a race footprint is a constitutional violation.
 *  3. The database: the candidacies CHECK constraint stores no other
 *     ground (Pham v. NY County).
 *
 * A 'validate' decision binds race_id from the candidate's deepest active
 * confirmation mapped through legislature_district_jurisdictions
 * (design §A B-5); payload race_id disambiguates multi-race matches
 * (e.g. bicameral type_a district + type_b at-large).
 */
class CandidateValidation implements FormHandler
{
    public const APPEAL_NOTICE =
        'This rejection may be appealed by filing F-IND-016 Constitutional Challenge (Art. IV).';

    public function __construct(
        private readonly RoleService $roles,
    ) {
    }

    public function module(): string
    {
        return 'elections';
    }

    public function event(): string
    {
        return 'candidacy.validation_decided';
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
        $decision = $payload['decision'] ?? null;

        if (! in_array($decision, ['validate', 'reject'], true)) {
            throw new ConstitutionalViolation(
                "F-ELB-002 requires decision 'validate' or 'reject'.",
                'CGA Forms Catalog (F-ELB-002)'
            );
        }

        $candidacy = Candidacy::query()->find($payload['candidacy_id'] ?? null);

        if ($candidacy === null) {
            throw new ConstitutionalViolation(
                'F-ELB-002 targets an unknown candidacy.',
                'CGA Forms Catalog (F-ELB-002)'
            );
        }

        if ($candidacy->status !== Candidacy::STATUS_REGISTERED) {
            throw new ConstitutionalViolation(
                "Candidacy [{$candidacy->id}] is not awaiting validation (status: {$candidacy->status}).",
                'CGA Forms Catalog (F-ELB-002)'
            );
        }

        $election = Election::query()->findOrFail($candidacy->election_id);
        $member   = BoardProvenance::resolveMember($actor, $election, 'F-ELB-002');

        // THE one permitted check (Art. I): an active association inside a
        // race footprint. Payload race_id narrows multi-race matches.
        $match = RaceFootprint::bestRaceForUser(
            (string) $candidacy->user_id,
            (string) $election->id,
            isset($payload['race_id']) ? (string) $payload['race_id'] : null,
        );

        if ($decision === 'validate') {
            if ($match === null) {
                throw new ConstitutionalViolation(
                    'Cannot validate: the candidate holds no active residency association inside any race '
                    . 'footprint of this election. File the rejection decision instead — residency is the '
                    . 'only ground (Art. I).',
                    'Art. I'
                );
            }

            $candidacy->forceFill([
                'status'                 => Candidacy::STATUS_VALIDATED,
                'race_id'                => (string) $match->race_id,
                'validated_at'           => now(),
                'validated_by_member_id' => (string) $member->id,
            ])->save();

            $this->roles->flushUser((string) $candidacy->user_id);

            return [
                'candidacy_id' => (string) $candidacy->id,
                'decision'     => 'validated',
                'race_id'      => (string) $match->race_id,
                'election_id'  => (string) $election->id,
            ];
        }

        // decision === 'reject'
        if ($match !== null) {
            throw new ConstitutionalViolation(
                'Cannot reject: the candidate holds an active residency association inside race '
                . "[{$match->race_id}]. Residency is the ONLY permissible rejection ground and it is "
                . 'satisfied — candidacy is an absolute right.',
                'Art. I'
            );
        }

        $candidacy->forceFill([
            'status'                 => Candidacy::STATUS_REJECTED,
            'rejection_reason'       => Candidacy::REJECTION_NO_RESIDENCY,
            'validated_by_member_id' => (string) $member->id,
        ])->save();

        $this->roles->flushUser((string) $candidacy->user_id);

        return [
            'candidacy_id'     => (string) $candidacy->id,
            'decision'         => 'rejected',
            'rejection_reason' => Candidacy::REJECTION_NO_RESIDENCY,
            'election_id'      => (string) $election->id,
            'appeal'           => self::APPEAL_NOTICE,
        ];
    }
}
