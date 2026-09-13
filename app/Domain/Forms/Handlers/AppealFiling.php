<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\CaseParty;
use App\Models\CourtCase;
use App\Models\Judiciary;
use App\Models\User;
use App\Services\Judiciary\CaseService;

/**
 * F-IND-027 — Appeal Filing (IO-2; operator ruling 2026-09-13,
 * appeals-workflow-rules = B).
 *
 * A party to a DECIDED or SENTENCED case appeals the judgement. The appeal is a
 * NEW `cases` row linked by appeal_of_case_id, heard by the parent judiciary
 * (judiciaries.parent_judiciary_id) or, when there is no parent, by the same
 * court en banc. The original moves decided|sentenced → appealed and is never
 * otherwise mutated — its verdict, opinion and sentencing rows stay untouched.
 *
 * Standing is association-only (Art. I — the same R-03 / R-21 gate a case
 * filing uses), plus the handler's own check that the actor is a PARTY to the
 * original case. Double jeopardy is untouched: an appeal is not a new
 * prosecution (Art. II §8), and a criminal appeal may only affirm or vacate,
 * never order a re-trial — no new criminal filing is created here, so
 * ConstitutionalValidator::checkCaseFiling is never engaged and never lifted.
 */
class AppealFiling implements FormHandler
{
    public function __construct(
        private readonly CaseService $cases,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'case.appealed';
    }

    public function requiredRoles(): array
    {
        // The association-standing gate a case filing uses (F-IND-017).
        return ['R-03', 'R-21'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation('F-IND-027 is filed by a party to the original case.', 'Art. I');
        }

        $caseId = $payload['case_id'] ?? null;

        // Load the original under a row lock inside the engine transaction so a
        // concurrent second appeal sees the moved status, not a stale one.
        $original = is_string($caseId) && $caseId !== ''
            ? CourtCase::query()->whereKey($caseId)->lockForUpdate()->first()
            : null;

        if ($original === null) {
            throw new ConstitutionalViolation('F-IND-027 requires a valid case_id (the original case).', 'CGA Forms Catalog');
        }

        // Only a decided or sentenced case may be appealed — a dismissed,
        // closed, or already-appealed case is not a live judgement to overturn
        // ("All other Judgements can be overturned only by proven contradictions
        // in law and errors found in the cases…" — Art. II §8).
        if (! in_array($original->status, CaseService::APPEALABLE_STATUSES, true)) {
            throw new ConstitutionalViolation(
                sprintf('Only a decided or sentenced judgement can be appealed — this case is %s.', $original->status),
                'Art. II §8'
            );
        }

        // Standing beyond association: the appellant must be a PARTY to the
        // original case (Art. I association standing, like a case filing).
        $isParty = CaseParty::query()
            ->where('case_id', (string) $original->id)
            ->where('party_user_id', (string) $actor->getKey())
            ->where('status', CaseParty::STATUS_ACTIVE)
            ->exists();

        if (! $isParty) {
            throw new ConstitutionalViolation('An appeal is filed by a party to the original case.', 'Art. I');
        }

        $grounds = trim((string) ($payload['grounds'] ?? ''));

        if ($grounds === '') {
            throw new ConstitutionalViolation(
                'F-IND-027 states the grounds — the contradiction in law or the error in the case (Art. II §8).',
                'Art. II §8'
            );
        }

        $statement = trim((string) ($payload['statement'] ?? ''));
        $claim = $statement === '' ? $grounds : $grounds."\n\n".$statement;

        // The appellate court: the parent judiciary, else the same court en banc.
        $original->loadMissing('judiciary');
        $court = $original->judiciary;

        if ($court === null) {
            throw new ConstitutionalViolation('The original case has no court of record to appeal from.', 'Art. IV §4');
        }

        // A parent that is not operating (dissolved, reverted, still forming)
        // cannot hear anything; the appeal is heard by the same court en banc,
        // the same as an apex court with no parent at all.
        $parent = $court->parentJudiciary; // BelongsTo parent_judiciary_id (null → apex).
        $enBanc = $parent === null || ! in_array((string) $parent->status, Judiciary::OPERATING_STATUSES, true);
        $appellateJudiciaryId = $enBanc ? (string) $court->id : (string) $parent->id;

        $appeal = $this->cases->openAppeal($original, [
            'judiciary_id' => $appellateJudiciaryId,
            'jurisdiction_id' => (string) $original->jurisdiction_id,
            'kind' => (string) $original->kind,
            'title' => 'Appeal of '.$original->docket_no,
            'statement_of_claim' => $claim,
            'filed_via_form' => 'F-IND-027',
            'filed_by_user_id' => (string) $actor->getKey(),
            'en_banc' => $enBanc,
            'parties' => $this->carryParties($original),
        ]);

        return [
            'case_id' => (string) $appeal->id,
            'docket_no' => (string) $appeal->docket_no,
            'appeal_of_case_id' => (string) $original->id,
            'appeal_of_docket_no' => (string) $original->docket_no,
            'kind' => (string) $appeal->kind,
            'appellate_judiciary_id' => $appellateJudiciaryId,
            'en_banc' => $enBanc,
        ];
    }

    /**
     * Carry the original's active party set onto the appeal case so the same
     * parties stand before the appellate court. Advocate retainers are not
     * carried — an appeal is a fresh appearance.
     *
     * @return list<array<string,mixed>>
     */
    private function carryParties(CourtCase $original): array
    {
        return CaseParty::query()
            ->where('case_id', (string) $original->id)
            ->where('status', CaseParty::STATUS_ACTIVE)
            ->get()
            ->map(fn (CaseParty $p) => [
                'party_role' => $p->party_role,
                'party_type' => $p->party_type,
                'party_user_id' => $p->party_user_id,
                'party_ref_type' => $p->party_ref_type,
                'party_ref_id' => $p->party_ref_id,
            ])
            ->all();
    }
}
