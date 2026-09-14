<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\CaseFiling;
use App\Models\CourtCase;
use App\Models\Opinion;
use App\Models\OpinionLawLink;
use App\Models\User;
use App\Services\Judiciary\CaseFilingService;
use App\Services\Judiciary\CaseService;
use App\Services\PublicRecordService;
use Illuminate\Support\Facades\DB;

/**
 * F-JDG-003 — Opinion / Ruling Filing (WF-JUD-03).
 *
 * Actor R-19/R-20. Writes the `opinions` row + the `opinion_law_links` it
 * cites/interprets, publishes kind `opinion`, and closes the case. An opinion
 * is COMMENTARY on the law (Art. IV §4) — it NEVER mutates laws/law_versions
 * (editing a law's text is the Art. IV §5 F-JDG-006 sibling path); the two are
 * deliberately separate tables.
 */
class OpinionRulingFiling implements FormHandler
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly CaseFilingService $filings,
        private readonly PublicRecordService $records,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'opinion.filed';
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
        $case = JudicialActor::case($payload, 'F-JDG-003');
        $seat = JudicialActor::seat($actor, (string) $case->judiciary_id, 'F-JDG-003');

        $panel = $case->panel;

        if ($panel === null) {
            throw new ConstitutionalViolation('An opinion issues from the panel that heard the case (Art. IV §4).', 'Art. IV §4');
        }

        $kind = (string) ($payload['kind'] ?? Opinion::KIND_MAJORITY);
        $title = trim((string) ($payload['title'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));

        if ($title === '' || $body === '') {
            throw new ConstitutionalViolation('F-JDG-003 names a title and the opinion body.', 'CGA Forms Catalog');
        }

        // IO-2 — the appellate outcome (operator ruling 2026-09-13,
        // appeals-workflow-rules = B). It is accepted ONLY on an appeal case (a
        // case with appeal_of_case_id), where it is REQUIRED and must be lawful
        // for the case kind: civil affirm/reverse/remand, criminal affirm/vacate
        // — a criminal appeal never orders a re-trial (Art. II §8). On a
        // first-instance opinion an appeal_outcome is refused.
        $appealOutcome = trim((string) ($payload['appeal_outcome'] ?? ''));
        $isAppeal = $case->isAppeal();

        if (! $isAppeal) {
            if ($appealOutcome !== '') {
                throw new ConstitutionalViolation(
                    'An appeal outcome is recorded only on an appeal case (one that appeals another).',
                    'Art. II §8'
                );
            }
        } else {
            $allowed = Opinion::APPEAL_OUTCOMES[$case->kind] ?? Opinion::APPEAL_OUTCOMES['civil'];

            if (! in_array($appealOutcome, $allowed, true)) {
                throw new ConstitutionalViolation(
                    sprintf(
                        'A %s appeal is recorded as %s — a criminal appeal may only affirm or vacate, never order a re-trial.',
                        $case->kind,
                        implode(' / ', $allowed)
                    ),
                    'Art. II §8'
                );
            }
        }

        // The original this appeal decides (untouched but for a public record).
        $original = $isAppeal ? CourtCase::query()->find((string) $case->appeal_of_case_id) : null;

        if ($actor !== null) {
            app(\App\Services\AchievementService::class)->awardSelf($actor, 'ACH-JUD-010');
        }

        return DB::transaction(function () use ($case, $seat, $panel, $kind, $title, $body, $payload, $actor, $appealOutcome, $isAppeal, $original) {
            $record = $this->records->publish(
                kind: 'opinion',
                title: $title,
                body: $body,
                attrs: [
                    'actor_user_id' => (string) $actor->getKey(),
                    'jurisdiction_id' => (string) $case->jurisdiction_id,
                    'via_form' => 'F-JDG-003',
                    'subject_type' => 'cases',
                    'subject_id' => (string) $case->id,
                ],
            );

            $opinion = Opinion::create([
                'case_id' => (string) $case->id,
                'panel_id' => (string) $panel->id,
                'authored_by_seat_id' => (string) $seat->id,
                'kind' => $kind,
                'title' => $title,
                'body' => $body,
                'appeal_outcome' => $appealOutcome !== '' ? $appealOutcome : null,
                'record_id' => (string) $record->id,
                'published_at' => now(),
            ]);

            // The laws the opinion interprets (commentary, never an edit).
            foreach ($payload['law_links'] ?? [] as $link) {
                $link = (array) $link;

                if (! isset($link['law_id'])) {
                    continue;
                }

                OpinionLawLink::create([
                    'opinion_id' => (string) $opinion->id,
                    'law_id' => (string) $link['law_id'],
                    'law_version_no' => isset($link['law_version_no']) ? (int) $link['law_version_no'] : null,
                    'relation' => (string) ($link['relation'] ?? OpinionLawLink::RELATION_INTERPRETS),
                    'note' => isset($link['note']) ? (string) $link['note'] : null,
                ]);
            }

            $this->filings->docket($case->refresh(), [
                'filing_form' => 'F-JDG-003',
                'filing_kind' => CaseFiling::KIND_OPINION,
                'filed_by_user_id' => (string) $actor->getKey(),
                'filed_by_role' => 'R-19',
                'title' => $title,
                'body' => $body,
                'enforce_attach_window' => false,
            ]);

            // The opinion is terminal — the case closes (decided/sentenced → closed).
            $this->cases->close($case->refresh());

            // IO-2 — record the appellate ruling's effect on the ORIGINAL as a
            // public record. The original's verdict / opinion / sentencing rows
            // are NEVER edited; a criminal vacate keeps double_jeopardy_locked.
            if ($isAppeal && $original !== null) {
                $this->cases->recordAppealEffectOnOriginal($original, $appealOutcome);
            }

            return [
                'case_id' => (string) $case->id,
                'opinion_id' => (string) $opinion->id,
                'kind' => $kind,
                'appeal_outcome' => $appealOutcome !== '' ? $appealOutcome : null,
                'appeal_of_case_id' => $isAppeal ? (string) $case->appeal_of_case_id : null,
                'law_links' => $opinion->lawLinks()->count(),
                'record_id' => (string) $record->id,
            ];
        });
    }
}
