<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\CourtCase;
use App\Models\Petition;
use App\Models\User;
use App\Services\Judiciary\CaseService;
use App\Services\PetitionService;

/**
 * F-JDG-008 — Petition Constitutional Review (Art. II §6/§8, WF-CIV-06). The
 * real review the Phase C stub anticipated (PHASE_E_DESIGN_challenge_law §C.2).
 * A seated judge (R-19/R-20) reviews a held petition's proposed law_text for
 * constitutionality BEFORE it reaches the ballot:
 *
 *  - cleared → petition validated, queued onward to the referendum.
 *  - struck  → petition invalidated, no referendum queued (the kill-path the
 *    petition model names: "unconstitutional finding (Phase E F-JDG-008) →
 *    invalidated").
 *
 * Petition state ownership stays in PetitionService::reviewByJudiciary (single
 * source of truth); this handler opens the review case (the petition's
 * review_case_id) and delegates.
 */
class PetitionConstitutionalReview implements FormHandler
{
    public function __construct(
        private readonly PetitionService $petitions,
        private readonly CaseService $cases,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'petition.reviewed';
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
        $petition = Petition::query()->find((string) ($payload['petition_id'] ?? ''));

        if ($petition === null) {
            throw new ConstitutionalViolation('F-JDG-008 names the petition it reviews (petition_id).', 'Art. II §6');
        }

        $judiciaryId = (string) ($payload['judiciary_id'] ?? '');

        if ($judiciaryId === '') {
            throw new ConstitutionalViolation('F-JDG-008 names the reviewing court (judiciary_id).', 'Art. II §6');
        }

        // The acting judge must be SEATED on THIS court.
        $seat = JudicialActor::seat($actor, $judiciaryId, 'F-JDG-008');

        $outcome = (string) ($payload['outcome'] ?? '');

        if (! in_array($outcome, ['cleared', 'struck'], true)) {
            throw new ConstitutionalViolation('A petition review clears or strikes (outcome).', 'Art. II §6');
        }

        $opinionText = trim((string) ($payload['opinion_text'] ?? ''));

        if ($opinionText === '') {
            throw new ConstitutionalViolation('A petition review carries its opinion (opinion_text).', 'Art. II §6');
        }

        // Open the review case (the petition's review_case_id; the cases agent
        // owns the lifecycle — here it is the hearing record).
        $case = $this->cases->open([
            'judiciary_id' => $judiciaryId,
            'jurisdiction_id' => (string) $petition->jurisdiction_id,
            'kind' => CourtCase::KIND_CONSTITUTIONAL,
            'title' => sprintf('Petition constitutional review — %s', $petition->title),
            'statement_of_claim' => $petition->law_text,
            'filed_via_form' => 'F-IND-016', // the cases CHECK permits this constitutional-filing form
            'filed_by_user_id' => $actor !== null ? (string) $actor->getKey() : null,
        ]);

        $reviewed = $this->petitions->reviewByJudiciary(
            $petition,
            $outcome,
            $opinionText,
            (string) $case->id,
            isset($payload['contradiction_citation']) ? (string) $payload['contradiction_citation'] : null,
        );

        return [
            'petition_id' => (string) $reviewed->id,
            'outcome' => $outcome,
            'review_case_id' => (string) $case->id,
            'status' => (string) $reviewed->status,
            'reviewed_by_seat' => (string) $seat->id,
            'jurisdiction_id' => (string) $reviewed->jurisdiction_id,
        ];
    }
}
