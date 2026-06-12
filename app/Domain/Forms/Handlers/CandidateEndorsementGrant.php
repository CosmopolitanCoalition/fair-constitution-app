<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Candidacy;
use App\Models\Endorsement;
use App\Models\EndorsementRequest as EndorsementRequestModel;
use App\Models\Organization;
use App\Models\User;
use App\Services\RoleService;

/**
 * F-ORG-002 — Candidate Endorsement Grant (R-23).
 *
 * The second half of the F-CAN-002 → F-ORG-002 handshake: the org's
 * agent (organizations.agent_user_id — the minimal Phase B R-23
 * substrate) grants or declines a pending request. A grant creates the
 * endorsements row FORCED PUBLIC (org endorsements are never anonymous —
 * my-record contract) and is the R-07 derivation source for the
 * candidate.
 */
class CandidateEndorsementGrant implements FormHandler
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
        return 'endorsement.decided';
    }

    public function requiredRoles(): array
    {
        return ['R-23'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $decision = $payload['decision'] ?? null;

        if (! in_array($decision, ['grant', 'decline'], true)) {
            throw new ConstitutionalViolation(
                "F-ORG-002 requires decision 'grant' or 'decline'.",
                'CGA Forms Catalog (F-ORG-002)'
            );
        }

        $request = EndorsementRequestModel::query()->find($payload['request_id'] ?? null);

        if ($request === null) {
            throw new ConstitutionalViolation(
                'F-ORG-002 targets an unknown endorsement request.',
                'CGA Forms Catalog (F-ORG-002)'
            );
        }

        if ($request->status !== EndorsementRequestModel::STATUS_PENDING) {
            throw new ConstitutionalViolation(
                "Endorsement request [{$request->id}] is already decided (status: {$request->status}).",
                'CGA Forms Catalog (F-ORG-002)'
            );
        }

        $organization = Organization::query()->findOrFail($request->organization_id);

        // The R-23 role gate proves agency over SOME org; the grant must
        // come from THIS org's agent. System filings (null actor) pass —
        // engine rule.
        if ($actor !== null && (string) $organization->agent_user_id !== (string) $actor->getKey()) {
            throw new ConstitutionalViolation(
                'Only the agent of the requested organization may decide its endorsements.',
                'CGA Forms Catalog (R-23)'
            );
        }

        $candidacy = Candidacy::query()->findOrFail($request->candidacy_id);

        if ($decision === 'decline') {
            $request->forceFill([
                'status'     => EndorsementRequestModel::STATUS_DECLINED,
                'decided_at' => now(),
            ])->save();

            return [
                'request_id'      => (string) $request->id,
                'decision'        => 'declined',
                'candidacy_id'    => (string) $candidacy->id,
                'organization_id' => (string) $organization->id,
            ];
        }

        // Endorsing a candidacy that is no longer standing is meaningless.
        $standing = in_array($candidacy->status, [
            Candidacy::STATUS_REGISTERED,
            Candidacy::STATUS_VALIDATED,
            Candidacy::STATUS_IN_POOL,
            Candidacy::STATUS_FINALIST,
        ], true);

        if (! $standing) {
            throw new ConstitutionalViolation(
                "Candidacy [{$candidacy->id}] is no longer standing (status: {$candidacy->status}).",
                'CGA Forms Catalog (F-ORG-002)'
            );
        }

        $endorsement = Endorsement::query()->create([
            'election_id'   => (string) $candidacy->election_id,
            'candidate_id'  => (string) $candidacy->id,
            'endorser_type' => Endorsement::ENDORSER_ORGANIZATION,
            'endorser_id'   => (string) $organization->id,
            'statement'     => isset($payload['statement']) ? (string) $payload['statement'] : null,
            'endorsed_at'   => now(),
            'is_active'     => true,
            'is_public'     => true, // org endorsements are forced public
        ]);

        $request->forceFill([
            'status'         => EndorsementRequestModel::STATUS_GRANTED,
            'decided_at'     => now(),
            'endorsement_id' => (string) $endorsement->id,
        ])->save();

        // R-07 derives from this row — flush the candidate's cache.
        $this->roles->flushUser((string) $candidacy->user_id);

        return [
            'request_id'      => (string) $request->id,
            'decision'        => 'granted',
            'endorsement_id'  => (string) $endorsement->id,
            'candidacy_id'    => (string) $candidacy->id,
            'organization_id' => (string) $organization->id,
        ];
    }
}
