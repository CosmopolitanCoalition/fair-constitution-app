<?php

namespace App\Services\Organizations;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\CgcIpRegisterEntry;
use App\Models\Organization;
use App\Services\AuditService;
use App\Services\PublicRecordService;

/**
 * D-O6 (PHASE_D_DESIGN_organizations §A D-O7) — THE ONLY WRITER of
 * `cgc_ip_register` (Art. III §5: CGC intellectual property is ALWAYS
 * public domain — never privatized; dedications are IRREVERSIBLE).
 *
 * dedicate() is the single public write surface; no update/delete method
 * exists here, on the model, or anywhere (source-scanned by
 * tests/Constitutional/CgcIpPublicDomainTest). cgc_to_private conversion
 * code never touches this table — existing dedications stand (WF-ORG-09).
 */
class CgcIpRegisterService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PublicRecordService $records,
    ) {
    }

    /**
     * Record one irreversible public-domain dedication, sealed to
     * public_records + the audit chain.
     */
    public function dedicate(
        Organization $org,
        string $asset,
        string $kind,
        ?string $description,
        string $viaForm,
        ?string $dedicatedByUserId = null,
    ): CgcIpRegisterEntry {
        if (! $org->is_cgc) {
            throw new ConstitutionalViolation(
                'IP dedications belong to Common Good Corporations — this organization is not a CGC.',
                'Art. III §5'
            );
        }

        if (! in_array($kind, CgcIpRegisterEntry::KINDS, true)) {
            throw new ConstitutionalViolation("Unknown IP kind [{$kind}].", 'Art. III §5 · as implemented');
        }

        $record = $this->records->publish(
            kind: 'act',
            title: "Public-domain dedication — {$asset}",
            body: sprintf(
                '%s (%s) of %s is dedicated to the public domain, irreversibly (Art. III §5). %s',
                $asset,
                $kind,
                $org->name,
                $description ?? ''
            ),
            attrs: [
                'actor_user_id'   => $dedicatedByUserId,
                'jurisdiction_id' => (string) $org->jurisdiction_id,
                'via_form'        => $viaForm,
                'subject_type'    => 'organizations',
                'subject_id'      => (string) $org->id,
            ],
        );

        $entry = $this->audit->append(
            module: 'organizations',
            event: 'cgc_ip.dedicated',
            payload: [
                'organization_id' => (string) $org->id,
                'asset'           => $asset,
                'kind'            => $kind,
                'status'          => CgcIpRegisterEntry::STATUS_PUBLIC_DOMAIN,
                'via_form'        => $viaForm,
                'record_id'       => (string) $record->id,
            ],
            ref: $viaForm,
            actorId: $dedicatedByUserId,
            jurisdictionId: (string) $org->jurisdiction_id,
        );

        return CgcIpRegisterEntry::create([
            'organization_id'      => (string) $org->id,
            'asset'                => $asset,
            'kind'                 => $kind,
            'description'          => $description,
            'status'               => CgcIpRegisterEntry::STATUS_PUBLIC_DOMAIN,
            'dedicated_via_form'   => $viaForm,
            'dedicated_by_user_id' => $dedicatedByUserId,
            'published_record_id'  => (string) $record->id,
            'audit_seq'            => (int) $entry->seq,
            'published_at'         => now(),
        ]);
    }
}
