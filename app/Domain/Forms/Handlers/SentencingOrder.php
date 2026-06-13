<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\CaseFiling;
use App\Models\SentencingOrder as SentencingOrderModel;
use App\Models\User;
use App\Models\Verdict;
use App\Services\Judiciary\CaseFilingService;
use App\Services\Judiciary\CaseService;
use App\Services\PublicRecordService;
use Illuminate\Support\Facades\DB;

/**
 * F-JDG-009 — Sentencing Order (WF-JUD-03).
 *
 * Actor R-19/R-20. Requires a guilty `verdicts` row (a sentence without a
 * guilty verdict is rejected); issues a `sentencing_orders` row. Case
 * `decided → sentenced`; published. Only a guilty CRIMINAL verdict reaches
 * sentencing — civil/acquittal skip straight to `closed`.
 */
class SentencingOrder implements FormHandler
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
        return 'sentence.issued';
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
        $case = JudicialActor::case($payload, 'F-JDG-009');
        $seat = JudicialActor::seat($actor, (string) $case->judiciary_id, 'F-JDG-009');

        $verdict = Verdict::query()->where('case_id', (string) $case->id)->whereNull('deleted_at')->first();

        if ($verdict === null || $verdict->outcome !== Verdict::OUTCOME_GUILTY) {
            throw new ConstitutionalViolation(
                'Sentencing requires a GUILTY verdict on the record — a sentence without a guilty verdict is rejected (Art. IV §4).',
                'Art. IV §4'
            );
        }

        $terms = trim((string) ($payload['terms'] ?? ''));

        if ($terms === '') {
            throw new ConstitutionalViolation('F-JDG-009 names the sentence terms.', 'CGA Forms Catalog');
        }

        return DB::transaction(function () use ($case, $seat, $verdict, $terms, $payload, $actor) {
            $record = $this->records->publish(
                kind: 'certification',
                title: sprintf('Sentencing order — %s (%s)', $case->title, $case->docket_no),
                body: $terms,
                attrs: [
                    'actor_user_id' => (string) $actor->getKey(),
                    'jurisdiction_id' => (string) $case->jurisdiction_id,
                    'via_form' => 'F-JDG-009',
                    'subject_type' => 'cases',
                    'subject_id' => (string) $case->id,
                ],
            );

            $order = SentencingOrderModel::create([
                'case_id' => (string) $case->id,
                'verdict_id' => (string) $verdict->id,
                'issued_by_seat_id' => (string) $seat->id,
                'terms' => $terms,
                'effective_at' => isset($payload['effective_at']) ? $payload['effective_at'] : now(),
                'expires_at' => $payload['expires_at'] ?? null,
                'status' => SentencingOrderModel::STATUS_ISSUED,
                'record_id' => (string) $record->id,
            ]);

            $this->filings->docket($case->refresh(), [
                'filing_form' => 'F-JDG-009',
                'filing_kind' => CaseFiling::KIND_SENTENCE,
                'filed_by_user_id' => (string) $actor->getKey(),
                'filed_by_role' => 'R-19',
                'title' => 'Sentencing order',
                'body' => $terms,
                'enforce_attach_window' => false,
            ]);

            $this->cases->sentence($case->refresh(), $order);

            return [
                'case_id' => (string) $case->id,
                'sentencing_order_id' => (string) $order->id,
                'verdict_id' => (string) $verdict->id,
                'record_id' => (string) $record->id,
            ];
        });
    }
}
