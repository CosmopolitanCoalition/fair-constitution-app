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
 *
 * Three actions dispatch on the 'action' payload key (the F-ORG-001
 * multi-action precedent), default 'grant' (operator ruling 2026-09-13 —
 * an organization may withdraw and re-endorse at ANY time while the
 * candidacy stands):
 *  - 'grant' (default): decide a pending request ('decision' grant/decline);
 *    a 'grant' on an already-granted request re-activates the row (a
 *    re-endorse after an earlier withdrawal). Byte-for-byte for existing
 *    callers, who pass 'decision' and no 'action'.
 *  - 'withdraw': deactivate the org's own live row for the candidacy.
 *  - 're-endorse': toggle the same logical row back active.
 * There is NO election-phase window on any action; the standing gate (the
 * candidacy must still stand) and the R-23 agent gate hold on every one.
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
        // Default 'grant' keeps the F-CAN-002 → F-ORG-002 handshake
        // byte-for-byte for existing callers (they pass 'decision', no
        // 'action'). The F-ORG-001 multi-action precedent: an 'action' key.
        $action = (string) ($payload['action'] ?? 'grant');

        return match ($action) {
            'grant'      => $this->grant($actor, $payload),
            'withdraw'   => $this->withdraw($actor, $payload),
            're-endorse' => $this->reEndorse($actor, $payload),
            default      => throw new ConstitutionalViolation(
                "Unknown F-ORG-002 action [{$action}].",
                'CGA Forms Catalog (F-ORG-002)'
            ),
        };
    }

    /**
     * The pending-request decision. A 'grant' on an already-granted request
     * re-activates the org's row instead of refusing (a re-endorse after a
     * prior withdrawal).
     */
    private function grant(?User $actor, array $payload): array
    {
        $decision = $payload['decision'] ?? null;

        if (! in_array($decision, ['grant', 'decline'], true)) {
            throw new ConstitutionalViolation(
                "F-ORG-002 requires decision 'grant' or 'decline'.",
                'CGA Forms Catalog (F-ORG-002)'
            );
        }

        $request = $this->requireRequest($payload);

        // A 'grant' on an already-granted request re-activates the org's row
        // (a re-endorse after an earlier withdrawal) rather than refusing.
        if ($decision === 'grant' && $request->status === EndorsementRequestModel::STATUS_GRANTED) {
            return $this->reEndorse($actor, $payload);
        }

        if ($request->status !== EndorsementRequestModel::STATUS_PENDING) {
            throw new ConstitutionalViolation(
                "Endorsement request [{$request->id}] is already decided (status: {$request->status}).",
                'CGA Forms Catalog (F-ORG-002)'
            );
        }

        $organization = Organization::query()->findOrFail($request->organization_id);
        $this->assertAgent($actor, $organization);

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
        $this->assertStanding($candidacy);

        $endorsement = Endorsement::recordFor($candidacy, Endorsement::ENDORSER_ORGANIZATION, (string) $organization->id, [
            'statement'     => isset($payload['statement']) ? (string) $payload['statement'] : null,
            'endorsed_at'   => now(),
            'withdrawn_at'  => null,
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

    /**
     * Deactivate the organization's own live endorsement row for the
     * candidacy — withdrawn_at set, is_active false. The granted request row
     * is left in place, so a later re-endorse toggles the SAME logical row
     * back active. Refuses when the org holds no live row.
     */
    private function withdraw(?User $actor, array $payload): array
    {
        $request = $this->requireRequest($payload);
        $organization = Organization::query()->findOrFail($request->organization_id);
        $this->assertAgent($actor, $organization);

        $candidacy = Candidacy::query()->findOrFail($request->candidacy_id);
        $this->assertStanding($candidacy);

        $existing = Endorsement::query()->logicalType(Endorsement::ENDORSER_ORGANIZATION)
            ->where('endorsements.election_id', $candidacy->election_id)
            ->where('endorsements.candidate_id', $candidacy->id)
            ->where('endorsements.endorser_id', (string) $organization->id)
            ->where('endorsements.is_active', true)
            ->whereNull('endorsements.withdrawn_at')
            ->first();

        if ($existing === null) {
            throw new ConstitutionalViolation(
                'This organization has no active endorsement of this candidacy to withdraw.',
                'CGA Forms Catalog (F-ORG-002)'
            );
        }

        $endorsement = Endorsement::recordFor($candidacy, Endorsement::ENDORSER_ORGANIZATION, (string) $organization->id, [
            'withdrawn_at' => now(),
            'is_active'    => false,
        ]);

        // R-07 derives from this row — flush the candidate's cache so a
        // withdrawn org endorsement no longer confers the role.
        $this->roles->flushUser((string) $candidacy->user_id);

        return [
            'request_id'      => (string) $request->id,
            'action'          => 'withdraw',
            'endorsement_id'  => (string) $endorsement->id,
            'candidacy_id'    => (string) $candidacy->id,
            'organization_id' => (string) $organization->id,
            'withdrawn'       => true,
        ];
    }

    /**
     * Toggle the organization's endorsement row back active (withdrawn_at
     * null, is_active true) via the same logical row — forced public, as all
     * org endorsements are. The granted request is refreshed to point at the
     * live row.
     */
    private function reEndorse(?User $actor, array $payload): array
    {
        $request = $this->requireRequest($payload);
        $organization = Organization::query()->findOrFail($request->organization_id);
        $this->assertAgent($actor, $organization);

        $candidacy = Candidacy::query()->findOrFail($request->candidacy_id);
        $this->assertStanding($candidacy);

        $endorsement = Endorsement::recordFor($candidacy, Endorsement::ENDORSER_ORGANIZATION, (string) $organization->id, [
            'endorsed_at'  => now(),
            'withdrawn_at' => null,
            'is_active'    => true,
            'is_public'    => true, // org endorsements are forced public
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
            'action'          => 're-endorse',
            'decision'        => 'granted',
            'endorsement_id'  => (string) $endorsement->id,
            'candidacy_id'    => (string) $candidacy->id,
            'organization_id' => (string) $organization->id,
        ];
    }

    private function requireRequest(array $payload): EndorsementRequestModel
    {
        $request = EndorsementRequestModel::query()->find($payload['request_id'] ?? null);

        if ($request === null) {
            throw new ConstitutionalViolation(
                'F-ORG-002 targets an unknown endorsement request.',
                'CGA Forms Catalog (F-ORG-002)'
            );
        }

        return $request;
    }

    /**
     * The R-23 role gate proves agency over SOME org; the action must come
     * from THIS org's agent. System filings (null actor) pass — engine rule.
     */
    private function assertAgent(?User $actor, Organization $organization): void
    {
        if ($actor !== null && (string) $organization->agent_user_id !== (string) $actor->getKey()) {
            throw new ConstitutionalViolation(
                'Only the agent of the requested organization may decide its endorsements.',
                'CGA Forms Catalog (R-23)'
            );
        }
    }

    private function assertStanding(Candidacy $candidacy): void
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
                'CGA Forms Catalog (F-ORG-002)'
            );
        }
    }
}
