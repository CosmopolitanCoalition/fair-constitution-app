<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\CaseFiling;
use App\Models\User;
use App\Services\Judiciary\CaseFilingService;
use App\Services\Judiciary\CaseService;
use App\Services\Judiciary\PanelService;

/**
 * F-JDG-001 — Case Acceptance / Panel Assignment (WF-JUD-03).
 *
 * Actor R-19/R-20 (a SEATED judge of the case's judiciary). Confirms
 * justiciability, fixes `court_severity` (the court's classification, NOT the
 * filer's), sets `jury_entitled`; then computes the panel size via the pure
 * PanelSizing::sizeFor, draws + screens + seats the bench (odd, ≥3,
 * severity-scaled; en banc for constitutional_major). Case
 * `filed → accepted → paneled` (or `dismissed`).
 */
class CaseAcceptanceAndPanelAssignment implements FormHandler
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly PanelService $panels,
        private readonly CaseFilingService $filings,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'case.accepted';
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
        $case = JudicialActor::case($payload, 'F-JDG-001');
        // The acting judge must be SEATED on THIS court.
        $seat = JudicialActor::seat($actor, (string) $case->judiciary_id, 'F-JDG-001');

        $action = (string) ($payload['action'] ?? 'accept');

        if ($action === 'dismiss') {
            $this->cases->dismiss($case, isset($payload['reason']) ? (string) $payload['reason'] : null);

            return ['action' => 'dismiss', 'case_id' => (string) $case->id];
        }

        $courtSeverity = (string) ($payload['court_severity'] ?? '');

        if ($courtSeverity === '') {
            throw new ConstitutionalViolation(
                'F-JDG-001 fixes the court\'s severity classification (it drives the panel size, not the filer\'s claim).',
                'Art. IV §4'
            );
        }

        $juryWaived = (bool) ($payload['jury_waived'] ?? false);

        // accept → paneled, all in one engine transaction.
        $this->cases->accept($case, $courtSeverity, $juryWaived);

        $panel = $this->panels->assignPanel(
            $case->refresh(),
            isset($payload['draw_seed']) ? (string) $payload['draw_seed'] : null,
            $this->normalizeRecusals($payload['recusals'] ?? []),
        );

        // Docket the panel-assignment order (the immutable docket).
        $this->filings->docket($case->refresh(), [
            'filing_form' => 'F-JDG-001',
            'filing_kind' => CaseFiling::KIND_PANEL_ASSIGNMENT,
            'filed_by_user_id' => (string) $actor->getKey(),
            'filed_by_role' => 'R-19',
            'title' => sprintf('Panel assigned — %d judges (%s)', (int) $panel->size, $panel->is_en_banc ? 'en banc' : $courtSeverity),
            'enforce_attach_window' => false,
        ]);

        return [
            'action' => 'accept',
            'case_id' => (string) $case->id,
            'court_severity' => $courtSeverity,
            'panel_id' => (string) $panel->id,
            'panel_size' => (int) $panel->size,
            'is_en_banc' => (bool) $panel->is_en_banc,
            'accepted_by_seat' => (string) $seat->id,
        ];
    }

    /** @return array<string,string> seat_id => reason */
    private function normalizeRecusals(mixed $recusals): array
    {
        if (! is_array($recusals)) {
            return [];
        }

        $out = [];

        foreach ($recusals as $seatId => $reason) {
            if (is_string($seatId) && is_string($reason) && trim($reason) !== '') {
                $out[$seatId] = $reason;
            }
        }

        return $out;
    }
}
