<?php

namespace App\Services\Organizations;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Organization;
use App\Models\OrgOwnershipStake;
use App\Models\OrgTransfer;
use App\Models\User;
use App\Services\ConstitutionalValidator;
use App\Services\PublicRecordService;
use App\Services\RoleService;

/**
 * D-O6 (PHASE_D_DESIGN_organizations §A/§D) — F-ORG-005 ownership
 * transfers (WF-ORG-06): MUTUAL consent, both sides, before anything
 * moves. Completion closes/opens stake rows in one transaction
 * (OrgOwnershipService).
 */
class OrgTransferService
{
    public function __construct(
        private readonly PublicRecordService $records,
        private readonly OrgOwnershipService $ownership,
        private readonly RoleService $roles,
    ) {
    }

    /** F-ORG-005 'initiate' — from-side consent recorded at filing. */
    public function initiate(Organization $org, User $agent, string $toType, string $toId, ?string $terms): OrgTransfer
    {
        if ($org->is_cgc) {
            throw new ConstitutionalViolation(
                'CGC ownership never transfers privately — reorganization/sale is a legislative act (F-LEG-027).',
                'Art. III §5'
            );
        }

        if ($org->status !== Organization::STATUS_ACTIVE) {
            throw new ConstitutionalViolation(
                "Organization [{$org->id}] is not active (status: {$org->status}).",
                'CGA Forms Catalog (F-ORG-005)'
            );
        }

        if (! in_array($toType, [OrgTransfer::PARTY_USERS, OrgTransfer::PARTY_ORGANIZATIONS], true)) {
            throw new ConstitutionalViolation("Unknown transferee type [{$toType}].", 'CGA Forms Catalog (F-ORG-005)');
        }

        $open = OrgTransfer::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', [OrgTransfer::STATUS_PROPOSED, OrgTransfer::STATUS_CONSENTED])
            ->exists();

        if ($open) {
            throw new ConstitutionalViolation(
                'An open transfer already exists for this organization.',
                'CGA Forms Catalog (F-ORG-005)'
            );
        }

        $transfer = OrgTransfer::create([
            'organization_id'      => (string) $org->id,
            'to_party_type'        => $toType,
            'to_party_id'          => $toId,
            'terms'                => $terms,
            'consent_from_at'      => now(),
            'consent_from_user_id' => (string) $agent->getKey(),
            'status'               => OrgTransfer::STATUS_PROPOSED,
        ]);

        $org->forceFill(['status' => Organization::STATUS_TRANSFER_PENDING])->save();

        return $transfer;
    }

    /** F-ORG-005 'consent' — the to-side (user self / org agent). */
    public function consent(OrgTransfer $transfer, User $actor): OrgTransfer
    {
        if ($transfer->status !== OrgTransfer::STATUS_PROPOSED) {
            throw new ConstitutionalViolation(
                "Transfer [{$transfer->id}] is not awaiting consent (status: {$transfer->status}).",
                'CGA Forms Catalog (F-ORG-005)'
            );
        }

        $this->assertToSideActor($transfer, $actor);

        $transfer->forceFill([
            'consent_to_at'      => now(),
            'consent_to_user_id' => (string) $actor->getKey(),
        ]);

        // The validator's mutual-consent rule — both or nothing.
        ConstitutionalValidator::assertTransferConsents(
            $transfer->consent_from_at !== null,
            $transfer->consent_to_at !== null,
        );

        $transfer->forceFill(['status' => OrgTransfer::STATUS_CONSENTED])->save();

        return $transfer;
    }

    /** F-ORG-005 'complete' — stake mutations, one transaction. */
    public function complete(OrgTransfer $transfer): OrgTransfer
    {
        if ($transfer->status !== OrgTransfer::STATUS_CONSENTED) {
            // The engine rejects completion with anything less than both
            // consents (the ONLY path overriding owner consent is monopoly
            // acquisition — a conversion, never a transfer).
            ConstitutionalValidator::assertTransferConsents(
                $transfer->consent_from_at !== null,
                $transfer->consent_to_at !== null,
            );

            throw new ConstitutionalViolation(
                "Transfer [{$transfer->id}] is not consented (status: {$transfer->status}).",
                'CGA Forms Catalog (F-ORG-005)'
            );
        }

        $org = Organization::query()->findOrFail($transfer->organization_id);

        $this->ownership->closeAllStakes($org);
        $this->ownership->openStake(
            $org,
            $transfer->to_party_type,
            (string) $transfer->to_party_id,
            100.0,
            OrgOwnershipStake::VIA_TRANSFER,
            (string) $transfer->id,
        );

        $transfer->forceFill([
            'status'       => OrgTransfer::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();

        // The org continues operating under the new owner.
        $org->forceFill([
            'status'    => Organization::STATUS_ACTIVE,
            'is_active' => true,
        ])->save();

        $this->records->publish(
            kind: 'act',
            title: "Ownership transferred — {$org->name}",
            body: sprintf(
                'Ownership of %s transferred to %s %s by mutual consent (WF-ORG-06).',
                $org->name,
                rtrim($transfer->to_party_type, 's'),
                (string) $transfer->to_party_id
            ),
            attrs: [
                'jurisdiction_id' => (string) $org->jurisdiction_id,
                'via_form'        => 'F-ORG-005',
                'via_workflow'    => 'WF-ORG-06',
                'subject_type'    => 'organizations',
                'subject_id'      => (string) $org->id,
            ],
        );

        $this->roles->flush();

        return $transfer;
    }

    private function assertToSideActor(OrgTransfer $transfer, User $actor): void
    {
        if ($transfer->to_party_type === OrgTransfer::PARTY_USERS) {
            if ((string) $transfer->to_party_id !== (string) $actor->getKey()) {
                throw new ConstitutionalViolation(
                    'Only the named transferee may consent to receive ownership.',
                    'CGA Forms Catalog (F-ORG-005)'
                );
            }

            return;
        }

        $agentId = Organization::query()->whereKey($transfer->to_party_id)->value('agent_user_id');

        if ($agentId === null || (string) $agentId !== (string) $actor->getKey()) {
            throw new ConstitutionalViolation(
                'Only the transferee organization\'s agent may consent on its behalf (R-23).',
                'CGA Forms Catalog (F-ORG-005)'
            );
        }
    }
}
