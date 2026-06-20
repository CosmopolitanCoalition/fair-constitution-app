<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Legislature;
use App\Models\MatrixCarveoutLog;
use App\Models\LegalComplianceRemoval as LegalComplianceRemovalModel;
use App\Models\User;
use App\Services\PublicRecordService;
use Illuminate\Support\Str;

/**
 * F-SOC-004 — the M-5 PHYSICAL-LAW legal-compliance removal. NOT a viewpoint carve-out and NOT a
 * constitutional office: a removal of already-posted ILLEGAL material (CSAM, a specific court order, a
 * true threat) for which the OPERATOR is criminally liable. It is `systemOnly()` from the citizen
 * engine's view — no citizen may file it; the operator plane is "system" here — and carries ZERO role
 * codes (the operator is authenticated by key-possession on its own plane; the validator additionally
 * verifies the operator_account_id is real + active). attestation_id is ALWAYS NULL on the resulting
 * matrix_carveout_log row, so an M-5 action can never be forged as a judicial M-1 order.
 *
 * Writes three durable, ATOMIC artifacts (one engine transaction): the immutable legal_compliance_removals
 * trail (the §2258A evidence record), the citizen-readable public_records('legal_compliance_removal')
 * (count + basis + list-SOURCE only — NEVER the hash/locator), and the matrix_carveout_log('m5_legal').
 * When the jurisdiction is SEATED, M-5 flips to BOTH: it ALSO files a mandatory DISCLOSURE REFERRAL to
 * the seated constitutional actors (referral_record_id) so the in-game justice can run its own response —
 * operator keeps the physical bytes + report, constitutional actors get the in-game case. Each in its lane.
 */
class LegalComplianceRemoval implements FormHandler
{
    public function __construct(private readonly PublicRecordService $records) {}

    public function module(): string
    {
        return 'records';
    }

    public function event(): string
    {
        return 'legal_compliance.removed';
    }

    public function requiredRoles(): array
    {
        return []; // ZERO role codes — the operator plane is not a constitutional office.
    }

    public function systemOnly(): bool
    {
        return true; // No citizen may file it; the operator-plane service files with a null actor.
    }

    public function handle(?User $actor, array $payload): array
    {
        $operatorId = (string) $payload['operator_account_id'];
        $legalBasis = (string) $payload['legal_basis'];
        $action = (string) $payload['action'];
        $eventId = (string) $payload['matrix_event_id'];
        $roomId = isset($payload['matrix_room_id']) ? (string) $payload['matrix_room_id'] : null;
        $jurisdictionId = isset($payload['jurisdiction_id']) ? (string) $payload['jurisdiction_id'] : null;
        $citation = isset($payload['statutory_citation']) ? (string) $payload['statutory_citation'] : null;
        $listSource = isset($payload['matched_list_source']) ? (string) $payload['matched_list_source'] : null;

        // The seatedness snapshot is computed authoritatively HERE (not trusted from the payload).
        $seated = $jurisdictionId !== null && Legislature::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('status', Legislature::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->exists();

        // (1) The citizen-readable transparency record — basis + list-SOURCE only, NEVER the hash.
        $record = $this->records->publish(
            kind: 'legal_compliance_removal',
            title: 'Legal-compliance removal (physical law)',
            body: sprintf(
                'An item was removed under the physical-law compliance floor; basis [%s]; action [%s]%s. '
                .'This is content-neutral compliance, NOT a viewpoint moderation decision.',
                $legalBasis,
                $action,
                $listSource !== null ? sprintf('; matched list source [%s]', $listSource) : ''
            ),
            attrs: [
                'jurisdiction_id' => $jurisdictionId,
                'via_form'        => 'F-SOC-004',
            ],
        );

        // (2) M-5 flips to BOTH once seated — a mandatory disclosure referral to the seated bodies so the
        //     in-game justice can ALSO act (its own M-1 case / governance response). No referral exists
        //     in bootstrap (there are no constitutional actors to disclose to).
        $referralRecordId = null;
        if ($seated) {
            $referral = $this->records->publish(
                kind: 'legal_compliance_removal',
                title: 'Legal-compliance disclosure referral to seated constitutional actors',
                body: sprintf(
                    'The operator removed an item under physical-law basis [%s] in this jurisdiction and '
                    .'holds the physical evidence/report per the applicable statute. The seated judiciary '
                    .'and legislature are hereby notified and may open their own response (an M-1 case or a '
                    .'governance action). No illegal content or locator is included in this referral.',
                    $legalBasis
                ),
                attrs: [
                    'jurisdiction_id' => $jurisdictionId,
                    'via_form'        => 'F-SOC-004',
                ],
            );
            $referralRecordId = (string) $referral->id;
        }

        // (3) The immutable evidence trail (append-only; no soft-deletes). attestation_id is NOT a column
        //     here — M-5 is operator-plane; the operator_account_id is the signer.
        $removal = LegalComplianceRemovalModel::create([
            'id'                  => (string) Str::uuid(),
            'matrix_event_id'     => $eventId,
            'matrix_room_id'      => $roomId,
            'operator_account_id' => $operatorId,
            'legal_basis'         => $legalBasis,
            'action'              => $action,
            'statutory_citation'  => $citation,
            'matched_list_source' => $listSource,
            'public_records_id'   => (string) $record->id,
            'jurisdiction_id'     => $jurisdictionId,
            'is_seated_at_time'   => $seated,
            'referral_record_id'  => $referralRecordId,
        ]);

        // (4) The machine carve-out log — carve_out m5_legal, attestation_id ALWAYS NULL (the anti-forgery
        //     discriminator + the mesh "censorship-without-an-order" detector: an m5_legal row MUST have a
        //     matching legal_compliance_removals row).
        $log = MatrixCarveoutLog::create([
            'id'                => (string) Str::uuid(),
            'matrix_room_id'    => $roomId ?? $eventId, // matrix_room_id is NOT NULL; fall back to the event coord
            'matrix_event_id'   => $eventId,
            'carve_out'         => MatrixCarveoutLog::CARVE_M5_LEGAL,
            'action'            => $action,
            'attestation_id'    => null,                // NEVER a judicial order
            'issuer_server_id'  => null,
            'public_records_id' => (string) $record->id,
            'jurisdiction_id'   => $jurisdictionId,
            'is_seated_at_time' => $seated,
        ]);

        return [
            'removal_id'         => (string) $removal->id,
            'legal_basis'       => $legalBasis,
            'action'            => $action,
            'matrix_event_id'   => $eventId,
            'record_id'         => (string) $record->id,
            'carveout_log_id'   => (string) $log->id,
            'is_seated_at_time' => $seated,
            'referral_record_id'=> $referralRecordId,
            'jurisdiction_id'   => $jurisdictionId,
        ];
    }
}
