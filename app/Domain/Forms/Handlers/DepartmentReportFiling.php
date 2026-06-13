<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\BoardSeat;
use App\Models\Department;
use App\Models\User;
use App\Services\Executive\DepartmentService;

/**
 * F-BOG-002 — Department Report Filing (WF-EXE-09).
 *
 * A seated governor (R-18) of THIS department files the due report (or a
 * special report) to the executive AND the legislature, published to the
 * public record. Filing a periodic report seeds the next obligation
 * (charter-data cadence); a passed due_on without a filing is swept to
 * overdue (DepartmentService::sweepOverdueReports, nightly).
 */
class DepartmentReportFiling implements FormHandler
{
    public function __construct(
        private readonly DepartmentService $departments,
    ) {}

    public function module(): string
    {
        return 'executive';
    }

    public function event(): string
    {
        return 'department.report_filed';
    }

    public function requiredRoles(): array
    {
        return ['R-18'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $department = Department::query()->find((string) ($payload['department_id'] ?? ''));

        if ($department === null) {
            throw new ConstitutionalViolation('F-BOG-002 names a chartered department.', 'Art. III §4');
        }

        $seat = $this->seatFor($actor, $department);

        $report = $this->departments->fileReport($department, $seat, $payload);

        return [
            'department_id' => (string) $department->id,
            'report_id' => (string) $report->id,
            'kind' => (string) $report->kind,
            'status' => (string) $report->status,
            'record_id' => $report->record_id !== null ? (string) $report->record_id : null,
            'filed_by_seat' => (string) $seat->id,
        ];
    }

    /** The actor's SEATED seat on this department's board (R-18), or throw. */
    private function seatFor(?User $actor, Department $department): BoardSeat
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'F-BOG-002 is filed by a seated board member — system filings name no seat.',
                'Art. III §4'
            );
        }

        $seat = BoardSeat::query()
            ->where('board_id', (string) $department->board_id)
            ->where('holder_user_id', (string) $actor->getKey())
            ->where('status', BoardSeat::STATUS_SEATED)
            ->first();

        if ($seat === null) {
            throw new ConstitutionalViolation(
                'F-BOG-002 is filed by a seated member of THIS department\'s board (R-18).',
                'Art. III §4'
            );
        }

        return $seat;
    }
}
