<?php

namespace App\Domain\Forms\Handlers\Concerns;

use App\Domain\Forms\Support\JudicialActor;
use App\Models\User;

/**
 * Shared docket logic for the advocate hearing filings (F-ADV-002 motion,
 * F-ADV-003 evidence, F-ADV-004 brief): resolve the case + the actor's
 * registered advocate, then append the filing under the attach-window gate.
 *
 * @property-read \App\Services\Judiciary\CaseFilingService $filings
 */
trait DocketsAdvocateFilings
{
    /**
     * @return array<string, mixed> the audit payload
     */
    protected function docketAdvocateFiling(?User $actor, array $payload, string $filingKind, string $formId): array
    {
        $case = JudicialActor::case($payload, $formId);
        $advocate = JudicialActor::advocate($actor, (string) $case->judiciary_id, $formId);

        $filing = $this->filings->docket($case, [
            'filing_form' => $formId,
            'filing_kind' => $filingKind,
            'filed_by_user_id' => (string) $actor->getKey(),
            'filed_by_role' => 'R-21',
            'advocate_id' => (string) $advocate->id,
            'title' => isset($payload['title']) ? (string) $payload['title'] : null,
            'body' => isset($payload['body']) ? (string) $payload['body'] : null,
        ]);

        return [
            'case_id' => (string) $case->id,
            'filing_id' => (string) $filing->id,
            'filing_kind' => $filingKind,
            'advocate_id' => (string) $advocate->id,
        ];
    }
}
