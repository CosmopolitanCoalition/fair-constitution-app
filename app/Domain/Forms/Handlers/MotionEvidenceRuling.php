<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\CaseFiling;
use App\Models\User;
use App\Services\Judiciary\CaseFilingService;

/**
 * F-JDG-014 — Motion or Evidence Ruling (operator ruling 2026-09-13; case-lifecycle-controls-shape A).
 *
 * Actor R-19/R-20 (a SEATED judge of THIS court). The docket is append-only,
 * so a ruling APPENDS a follow-up filing of the ruled kind (motion / evidence)
 * carrying `ruling` (granted / denied / admitted / excluded) and the written
 * `ruling_reason` — it never edits the original filing (Art. IV §4). The court
 * instrument bypasses the adversarial attach-window (enforce_attach_window
 * false), so the bench may rule during the hearing.
 */
class MotionEvidenceRuling implements FormHandler
{
    private const RULINGS = [
        CaseFiling::RULING_GRANTED,
        CaseFiling::RULING_DENIED,
        CaseFiling::RULING_ADMITTED,
        CaseFiling::RULING_EXCLUDED,
    ];

    public function __construct(
        private readonly CaseFilingService $filings,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'case.filing_ruled';
    }

    public function requiredRoles(): array
    {
        return ['R-19', 'R-20'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $case = JudicialActor::case($payload, 'F-JDG-014');
        JudicialActor::seat($actor, (string) $case->judiciary_id, 'F-JDG-014');

        $ruling = (string) ($payload['ruling'] ?? '');

        if (! in_array($ruling, self::RULINGS, true)) {
            throw new ConstitutionalViolation(
                'A ruling grants or denies a motion, or admits or excludes evidence.',
                'Art. IV §4'
            );
        }

        $reason = trim((string) ($payload['ruling_reason'] ?? ''));

        if ($reason === '') {
            throw new ConstitutionalViolation(
                'A ruling states its written reason — the docket records why the court ruled.',
                'Art. IV §4'
            );
        }

        // The follow-up appends against the kind it rules on so it lands in
        // that docket table; evidence takes an admissibility ruling, a motion
        // is granted or denied.
        $kind = (string) ($payload['filing_kind'] ?? CaseFiling::KIND_MOTION);

        if (! in_array($kind, [CaseFiling::KIND_MOTION, CaseFiling::KIND_EVIDENCE], true)) {
            throw new ConstitutionalViolation(
                'A ruling attaches to a motion or an evidence filing.',
                'Art. IV §4'
            );
        }

        // The ruling vocabulary follows the kind: a motion is granted or
        // denied, evidence is admitted or excluded. The page constrains the
        // options; the server holds the same line for a crafted POST.
        $lawful = $kind === CaseFiling::KIND_EVIDENCE
            ? [CaseFiling::RULING_ADMITTED, CaseFiling::RULING_EXCLUDED]
            : [CaseFiling::RULING_GRANTED, CaseFiling::RULING_DENIED];

        if (! in_array($ruling, $lawful, true)) {
            throw new ConstitutionalViolation(
                $kind === CaseFiling::KIND_EVIDENCE
                    ? 'Evidence is admitted or excluded; it is not granted or denied.'
                    : 'A motion is granted or denied; it is not admitted or excluded.',
                'Art. IV §4'
            );
        }

        $title = trim((string) ($payload['title'] ?? ''));

        $filing = $this->filings->docket($case, [
            'filing_form' => 'F-JDG-014',
            'filing_kind' => $kind,
            'filed_by_user_id' => (string) $actor->getKey(),
            'filed_by_role' => 'R-19',
            'title' => $title !== '' ? $title : ($kind === CaseFiling::KIND_EVIDENCE ? 'Admissibility ruling' : 'Motion ruling'),
            'body' => isset($payload['references_filing_id']) ? 'Ruling on filing '.((string) $payload['references_filing_id']) : null,
            'ruling' => $ruling,
            'ruling_reason' => $reason,
            'enforce_attach_window' => false,
        ]);

        return [
            'case_id' => (string) $case->id,
            'filing_id' => (string) $filing->id,
            'filing_kind' => $kind,
            'ruling' => $ruling,
        ];
    }
}
