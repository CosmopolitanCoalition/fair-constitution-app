<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesChairActor;
use App\Models\Committee;
use App\Models\CommitteeReport;
use App\Models\User;
use App\Services\PublicRecordService;

/**
 * F-CHR-004 — Committee Report Filing (chamber ops §C.5). The report body
 * is a public record (kind `other` — the C-1 enum carries no dedicated
 * report kind; the via_form pins the meaning); the committee_reports row
 * indexes it, optionally against a bill.
 */
class CommitteeReportFiling implements FormHandler
{
    use ResolvesChairActor;

    public function __construct(
        private readonly PublicRecordService $records,
    ) {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'committee.report_filed';
    }

    public function requiredRoles(): array
    {
        return ['R-12', 'R-13'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $committee = $this->committeeFrom($payload, 'F-CHR-004');

        if ($committee->status !== Committee::STATUS_SEATED) {
            throw new ConstitutionalViolation('Reports are filed by SEATED committees.', 'CGA Forms Catalog (F-CHR-004)');
        }

        $chair = $this->chairActor($actor, $committee, $payload, 'F-CHR-004');

        $title = trim((string) ($payload['title'] ?? ''));
        $body  = trim((string) ($payload['body'] ?? ''));

        if ($title === '' || $body === '') {
            throw new ConstitutionalViolation(
                'A committee report carries a title and a body.',
                'CGA Forms Catalog (F-CHR-004)'
            );
        }

        $legislature = $committee->legislature()->firstOrFail();

        $record = $this->records->publish(
            kind: 'other',
            title: sprintf('Committee report — %s: %s', $committee->name, $title),
            body: $body,
            attrs: [
                'actor_user_id'   => (string) $chair->user_id,
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-CHR-004',
                'subject_type'    => 'committees',
                'subject_id'      => (string) $committee->id,
            ],
        );

        $report = CommitteeReport::create([
            'committee_id'       => $committee->id,
            'bill_id'            => $payload['bill_id'] ?? null,
            'filed_by_member_id' => $chair->id,
            'report_record_id'   => (string) $record->id,
        ]);

        return [
            'committee_id'     => (string) $committee->id,
            'report_id'        => (string) $report->id,
            'report_record_id' => (string) $record->id,
            'filed_by'         => (string) $chair->id,
            'bill_id'          => $payload['bill_id'] ?? null,
        ];
    }
}
