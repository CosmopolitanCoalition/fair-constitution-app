<?php

namespace App\Services\Judiciary;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\CaseFiling;
use App\Models\CourtCase;
use App\Services\AuditService;
use App\Services\PublicRecordService;
use Illuminate\Support\Facades\DB;

/**
 * The append-only docket writer (PHASE_E_DESIGN_cases_juries §C). docket()
 * validates the attach-window against cases.status, writes the case_filings
 * row, seals it to the chain, and publishes the public-docket record. Judge
 * rulings on motions/evidence (granted/denied/admitted/excluded + written
 * reasons) APPEND a follow-up filing row, never edit the original.
 *
 * Attach-window rule (the advocate-console "no filings accepted" gate):
 *   - motions  — before and during `heard`;
 *   - evidence — on the open evidence docket (accepted..heard);
 *   - briefs   — until `deliberation` (rejected once deliberation opens).
 */
class CaseFilingService
{
    /** filing_kind => the case statuses in which it may attach. */
    private const ATTACH_WINDOWS = [
        CaseFiling::KIND_MOTION => [
            CourtCase::STATUS_ACCEPTED, CourtCase::STATUS_PANELED,
            CourtCase::STATUS_JURY_EMPANELED, CourtCase::STATUS_HEARD,
        ],
        CaseFiling::KIND_EVIDENCE => [
            CourtCase::STATUS_ACCEPTED, CourtCase::STATUS_PANELED,
            CourtCase::STATUS_JURY_EMPANELED, CourtCase::STATUS_HEARD,
        ],
        CaseFiling::KIND_BRIEF => [
            CourtCase::STATUS_ACCEPTED, CourtCase::STATUS_PANELED,
            CourtCase::STATUS_JURY_EMPANELED, CourtCase::STATUS_HEARD,
        ],
    ];

    public function __construct(
        private readonly PublicRecordService $records,
        private readonly AuditService $audit,
    ) {}

    /**
     * Docket one filing. The attach-window gate runs against the LIVE
     * cases.status; a brief filed after `deliberation` is rejected with the
     * citation. Every filing lands on the public docket.
     *
     * @param  array{
     *     filing_form:string, filing_kind:string,
     *     filed_by_user_id?:?string, filed_by_role?:?string, advocate_id?:?string,
     *     title?:?string, body?:?string, ruling?:?string, ruling_reason?:?string,
     *     enforce_attach_window?:bool
     * } $attrs
     */
    public function docket(CourtCase $case, array $attrs): CaseFiling
    {
        $kind = (string) $attrs['filing_kind'];

        // Adversarial filings (motion/evidence/brief) honour the attach-window;
        // court-issued instruments (orders, panel/jury/opinion/sentence/warrant)
        // bypass it (they ARE the transitions).
        if (($attrs['enforce_attach_window'] ?? true) && isset(self::ATTACH_WINDOWS[$kind])) {
            $window = self::ATTACH_WINDOWS[$kind];

            if (! in_array($case->status, $window, true)) {
                throw new ConstitutionalViolation(
                    sprintf(
                        'A %s cannot be docketed while the case is %s — its attach-window has closed (Art. IV §4).',
                        $kind,
                        $case->status
                    ),
                    'Art. IV §4'
                );
            }
        }

        return DB::transaction(function () use ($case, $attrs, $kind): CaseFiling {
            $record = $this->records->publish(
                kind: 'testimony',
                title: sprintf('Docket — %s (%s): %s', $case->docket_no, $kind, (string) ($attrs['title'] ?? $kind)),
                body: $attrs['body'] ?? null,
                attrs: [
                    'actor_user_id' => $attrs['filed_by_user_id'] ?? null,
                    'jurisdiction_id' => (string) $case->jurisdiction_id,
                    'via_form' => (string) $attrs['filing_form'],
                    'subject_type' => 'cases',
                    'subject_id' => (string) $case->id,
                ],
            );

            $filing = CaseFiling::create([
                'case_id' => (string) $case->id,
                'filing_form' => (string) $attrs['filing_form'],
                'filing_kind' => $kind,
                'filed_by_user_id' => $attrs['filed_by_user_id'] ?? null,
                'filed_by_role' => $attrs['filed_by_role'] ?? null,
                'advocate_id' => $attrs['advocate_id'] ?? null,
                'title' => $attrs['title'] ?? null,
                'body' => $attrs['body'] ?? null,
                'ruling' => $attrs['ruling'] ?? null,
                'ruling_reason' => $attrs['ruling_reason'] ?? null,
                'accepted_at_state' => $case->status,
                'record_id' => (string) $record->id,
                'audit_seq' => (int) $record->audit_seq,
            ]);

            // The PK is `seq`; the uuid `id` is DB-generated — reload it so
            // callers can reference the cross-instance id (forward refs).
            $filing->refresh();

            $this->audit->append(
                module: 'judiciary',
                event: 'docket.filed',
                payload: [
                    'case_id' => (string) $case->id,
                    'filing_id' => (string) $filing->id,
                    'filing_form' => (string) $attrs['filing_form'],
                    'filing_kind' => $kind,
                    'at_state' => $case->status,
                ],
                ref: (string) $attrs['filing_form'],
                jurisdictionId: (string) $case->jurisdiction_id,
            );

            return $filing;
        });
    }
}
