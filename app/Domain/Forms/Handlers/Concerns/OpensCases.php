<?php

namespace App\Domain\Forms\Handlers\Concerns;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Advocate;
use App\Models\CaseParty;
use App\Models\CourtCase;
use App\Models\User;
use App\Services\Judiciary\CaseFilingService;
use App\Services\Judiciary\CaseService;

/**
 * Shared case-opening logic for F-IND-017 (self/advocate) and F-ADV-001
 * (always advocate). Builds the `cases` row, the opening party set, and the
 * opening `case_filings` docket row; standing is association-only (Art. I).
 *
 * @property-read CaseService $cases
 * @property-read CaseFilingService $filings
 */
trait OpensCases
{
    /**
     * Open a case in `filed`. When $advocate is non-null the case is filed ON
     * BEHALF of $onBehalfOfUserId (F-ADV-001 / the advocate path of F-IND-017);
     * otherwise the actor files for themselves.
     *
     * @return array<string, mixed> the audit payload
     */
    protected function openCase(?User $actor, array $payload, ?Advocate $advocate, string $formId): array
    {
        $judiciaryId = (string) ($payload['judiciary_id'] ?? '');
        $jurisdictionId = (string) ($payload['jurisdiction_id'] ?? '');
        $kind = (string) ($payload['kind'] ?? '');
        $title = trim((string) ($payload['title'] ?? ''));

        if ($judiciaryId === '' || $jurisdictionId === '' || $title === '') {
            throw new ConstitutionalViolation(
                "{$formId} names the court (judiciary_id), the resolved scale (jurisdiction_id), and a title.",
                'CGA Forms Catalog'
            );
        }

        if (! in_array($kind, [
            CourtCase::KIND_CIVIL, CourtCase::KIND_CRIMINAL, CourtCase::KIND_ADMINISTRATIVE,
        ], true)) {
            // F-IND-016 (constitutional) is the sibling design's filing.
            throw new ConstitutionalViolation(
                "{$formId} files civil, criminal, or administrative cases.",
                'Art. IV §4'
            );
        }

        $filedByUserId = $actor !== null ? (string) $actor->getKey() : null;
        $onBehalfOf = isset($payload['filed_on_behalf_of_user_id'])
            ? (string) $payload['filed_on_behalf_of_user_id']
            : null;

        // Make the resolved filer available to buildParties (default party set).
        $payload['filed_by_user_id'] = $filedByUserId;

        $case = $this->cases->open([
            'judiciary_id' => $judiciaryId,
            'jurisdiction_id' => $jurisdictionId,
            'kind' => $kind,
            'title' => $title,
            'statement_of_claim' => isset($payload['statement_of_claim']) ? (string) $payload['statement_of_claim'] : null,
            'claimed_severity' => isset($payload['claimed_severity']) ? (string) $payload['claimed_severity'] : null,
            'filed_via_form' => $formId,
            'filed_by_user_id' => $filedByUserId,
            'filed_on_behalf_of_user_id' => $onBehalfOf,
            'advocate_id' => $advocate !== null ? (string) $advocate->id : null,
            'parties' => $this->buildParties($payload, $advocate),
        ]);

        // The opening docket entry (the case_filing kind on the immutable docket).
        $this->filings->docket($case, [
            'filing_form' => $formId,
            'filing_kind' => 'case_filing',
            'filed_by_user_id' => $filedByUserId,
            'filed_by_role' => $advocate !== null ? 'R-21' : 'R-03',
            'advocate_id' => $advocate !== null ? (string) $advocate->id : null,
            'title' => $title,
            'body' => isset($payload['statement_of_claim']) ? (string) $payload['statement_of_claim'] : null,
            'enforce_attach_window' => false,
        ]);

        return [
            'case_id' => (string) $case->id,
            'docket_no' => (string) $case->docket_no,
            'kind' => (string) $case->kind,
            'judiciary_id' => $judiciaryId,
            'advocate_id' => $advocate !== null ? (string) $advocate->id : null,
        ];
    }

    /**
     * Build the opening party set. A criminal case names an `accused`
     * individual (the double-jeopardy match key); otherwise the filer is the
     * plaintiff/petitioner. The retainer note is recorded with an advocate
     * filing ("the retainer is recorded with the filing").
     *
     * @return list<array<string,mixed>>
     */
    private function buildParties(array $payload, ?Advocate $advocate): array
    {
        $parties = [];

        // Caller-supplied explicit parties (the structured filing path).
        foreach ($payload['parties'] ?? [] as $party) {
            $parties[] = (array) $party;
        }

        if ($parties !== []) {
            return $parties;
        }

        // Minimal default: a named accused (criminal) on the defense side, and
        // the filer/client on the complaining side.
        $kind = (string) ($payload['kind'] ?? '');

        if ($kind === CourtCase::KIND_CRIMINAL && isset($payload['accused_user_id'])) {
            $parties[] = [
                'party_role' => CaseParty::ROLE_ACCUSED,
                'party_type' => CaseParty::TYPE_INDIVIDUAL,
                'party_user_id' => (string) $payload['accused_user_id'],
                'represented_by_advocate_id' => $advocate !== null ? (string) $advocate->id : null,
                'retainer_note' => isset($payload['retainer_note']) ? (string) $payload['retainer_note'] : null,
            ];
        }

        $complainantId = $payload['filed_on_behalf_of_user_id']
            ?? $payload['filed_by_user_id']
            ?? null;

        if ($complainantId !== null) {
            $parties[] = [
                'party_role' => $kind === CourtCase::KIND_CRIMINAL ? CaseParty::ROLE_PROSECUTION : CaseParty::ROLE_PLAINTIFF,
                'party_type' => CaseParty::TYPE_INDIVIDUAL,
                'party_user_id' => (string) $complainantId,
            ];
        }

        return $parties;
    }
}
