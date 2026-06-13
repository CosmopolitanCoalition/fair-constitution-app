<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;

/**
 * F-IND-011 — Candidacy Registration (R-03).
 *
 * Art. I — candidacy is an ABSOLUTE right of residency. The association
 * is the ONLY requirement: this form is a RIGHTS_AUTOMATIC_FORMS member
 * (ConstitutionalValidator rejects eligibility riders pre-commit), and
 * this handler checks nothing beyond:
 *
 *  - the election's approval phase is open (CLK-18);
 *  - the office's jurisdiction is in the actor's association chain
 *    (an active residency confirmation on the election's jurisdiction —
 *    the verification sweep guarantees ancestors are covered);
 *  - the residency attestation checkbox (`residency_attested: true`) —
 *    the only attestation that may exist (design §A B-5);
 *  - one candidacy per election (DB unique).
 *
 * The row starts 'registered'; F-ELB-002 binds race_id and validates.
 * There is no write-in flag: write-in eligibility is DERIVED — every
 * validated candidacy that misses the finalist cut ('non_finalist')
 * remains rankable on ballots (right to stand preserved).
 */
class CandidacyRegistration implements FormHandler
{
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
        return 'candidacy.registered';
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
                'A candidacy belongs to a person — the system cannot register one.',
                'Art. I'
            );
        }

        $election = Election::query()->find($payload['election_id'] ?? null);

        // The residency attestation belongs to PUBLIC-office races (Art. I).
        // Org-board races (Phase D) gate on class membership instead — a
        // residency demand there would be the forbidden extra condition.
        $isOrgElection = $election !== null
            && in_array($election->kind, [Election::KIND_ORG_BOARD_OWNER, Election::KIND_ORG_BOARD_WORKER], true);

        if (! $isOrgElection && ($payload['residency_attested'] ?? false) !== true) {
            throw new ConstitutionalViolation(
                'Candidacy registration requires the residency attestation checkbox — the only '
                . 'attestation that may exist.',
                'Art. I'
            );
        }

        if ($election === null) {
            throw new ConstitutionalViolation(
                'F-IND-011 targets an unknown election.',
                'CGA Forms Catalog (F-IND-011)'
            );
        }

        if ($election->status !== Election::STATUS_APPROVAL_OPEN) {
            throw new ConstitutionalViolation(
                "Candidacy registration is open during the approval phase only (election status: {$election->status}).",
                'Art. II §2 · as implemented'
            );
        }

        $userId = (string) $actor->getKey();

        // Phase D (PHASE_D_DESIGN_organizations §C.1): org-board races are
        // Art. III §6 BOARD STRUCTURE, not Art. I public office — the
        // CLASS check replaces the association check (the single
        // permissible ground mirrors 'no_residency_association' as
        // 'no_class_membership'), and no I-ELB sits in the loop: the
        // candidacy auto-validates against the election's race.
        $orgRace = in_array($election->kind, [Election::KIND_ORG_BOARD_OWNER, Election::KIND_ORG_BOARD_WORKER], true)
            ? \App\Models\ElectionRace::query()->where('election_id', $election->id)->orderBy('created_at')->first()
            : null;

        if ($orgRace !== null) {
            $eligible = app(\App\Services\Organizations\OrgElectorateService::class)->isEligible($userId, $orgRace);

            if (! $eligible) {
                throw new ConstitutionalViolation(
                    'no_class_membership — this board race belongs to the '
                    . ($orgRace->electorate_type === 'workers' ? 'worker' : 'owner')
                    . ' class; the class check is the only permissible ground (residency and identity '
                    . 'conditions remain forbidden on org races).',
                    'Art. III §6'
                );
            }
        } else {
            // Office ∈ association chain: an active confirmation on the
            // election's jurisdiction (ancestor sweep covers nesting).
            $associated = DB::table('residency_confirmations')
                ->where('user_id', $userId)
                ->where('jurisdiction_id', (string) $election->jurisdiction_id)
                ->where('is_active', true)
                ->exists();

            if (! $associated) {
                throw new ConstitutionalViolation(
                    "The election's jurisdiction is not in your association chain — candidacy follows "
                    . 'jurisdictional residency (and nothing else).',
                    'Art. I'
                );
            }
        }

        $existing = Candidacy::query()
            ->where('election_id', (string) $election->id)
            ->where('user_id', $userId)
            ->exists();

        if ($existing) {
            throw new ConstitutionalViolation(
                'You already hold a candidacy in this election (one per election).',
                'CGA Forms Catalog (F-IND-011)'
            );
        }

        $candidacy = Candidacy::query()->create([
            'election_id'           => (string) $election->id,
            // Org races auto-validate in-handler (no I-ELB in the loop —
            // the class check IS the validation, §C.2).
            'race_id'               => $orgRace?->id,
            'user_id'               => $userId,
            'status'                => $orgRace !== null ? Candidacy::STATUS_VALIDATED : Candidacy::STATUS_REGISTERED,
            'validated_at'          => $orgRace !== null ? now() : null,
            'platform_statement'    => isset($payload['platform_statement']) ? (string) $payload['platform_statement'] : null,
            'position_tags'         => CampaignProfileSetup::cleanTags($payload['position_tags'] ?? []),
            'residency_attested_at' => $orgRace !== null ? null : now(),
        ]);

        // R-06 derives from this row — flush the request cache.
        $this->roles->flushUser($userId);

        return [
            'candidacy_id'    => (string) $candidacy->id,
            'election_id'     => (string) $election->id,
            'jurisdiction_id' => (string) $election->jurisdiction_id,
        ];
    }
}
