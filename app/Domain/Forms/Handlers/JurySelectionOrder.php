<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\CaseFiling;
use App\Models\User;
use App\Services\Judiciary\CaseFilingService;
use App\Services\Judiciary\JuryService;

/**
 * F-JDG-002 — Jury Selection Order (WF-JUD-03, "a jury of their peers").
 *
 * Actor R-19/R-20. Creates the `juries` row + the random draw of
 * `jury_members` from the eligible jurisdictionally-associated pool (the seed
 * published to the audit chain). Case `→ jury_empaneled`.
 */
class JurySelectionOrder implements FormHandler
{
    public function __construct(
        private readonly JuryService $juries,
        private readonly CaseFilingService $filings,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'jury.selection_ordered';
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
        $case = JudicialActor::case($payload, 'F-JDG-002');
        JudicialActor::seat($actor, (string) $case->judiciary_id, 'F-JDG-002');

        $jury = $this->juries->empanel(
            $case,
            isset($payload['draw_seed']) ? (string) $payload['draw_seed'] : null,
            isset($payload['seats']) ? (int) $payload['seats'] : null,
            isset($payload['alternates']) ? (int) $payload['alternates'] : null,
        );

        $filing = $this->filings->docket($case->refresh(), [
            'filing_form' => 'F-JDG-002',
            'filing_kind' => CaseFiling::KIND_JURY_ORDER,
            'filed_by_user_id' => (string) $actor->getKey(),
            'filed_by_role' => 'R-19',
            'title' => sprintf('Jury selection order — %d jurors + %d alternates drawn (seed published)', (int) $jury->seats, (int) $jury->alternates),
            'enforce_attach_window' => false,
        ]);

        // Backfill the selection-order reference on the jury (forward ref).
        $jury->forceFill(['selection_order_id' => (string) $filing->id])->save();

        return [
            'case_id' => (string) $case->id,
            'jury_id' => (string) $jury->id,
            'pool_size' => (int) $jury->pool_size,
            'seats' => (int) $jury->seats,
            'alternates' => (int) $jury->alternates,
            'draw_seed' => (string) $jury->draw_seed,
        ];
    }
}
