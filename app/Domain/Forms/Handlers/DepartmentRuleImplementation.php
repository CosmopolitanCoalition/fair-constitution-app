<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\BoardSeat;
use App\Models\Department;
use App\Models\User;
use App\Services\Executive\DepartmentService;

/**
 * F-BOG-001 — Department Rule Implementation (WF-EXE-08).
 *
 * A seated governor (R-18) of THIS department files a versioned rule
 * citing a LIVE enabling instrument — the charter, an in-force law, or an
 * ACTIVE emergency power (F-BOG-001 is one of the two
 * EMERGENCY_ENABLED_FORMS; an emergency-enabled rule expires WITH the
 * power, the CLK-03 cascade). Rules implement — they cannot exceed — the
 * charter and the enabling act; DepartmentService::fileRule +
 * EnablingInstruments enforce the bounds and reject scope overruns with
 * the citation.
 */
class DepartmentRuleImplementation implements FormHandler
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
        return 'department.rule_filed';
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
            throw new ConstitutionalViolation('F-BOG-001 names a chartered department.', 'Art. III §4');
        }

        if (trim((string) ($payload['name'] ?? '')) === '' || trim((string) ($payload['text'] ?? '')) === '') {
            throw new ConstitutionalViolation(
                'A department rule carries a name and text, and cites the act that enables it.',
                'Art. III §4'
            );
        }

        $seat = $this->seatFor($actor, $department);

        $rule = $this->departments->fileRule($department, $seat, $payload);

        return [
            'department_id' => (string) $department->id,
            'rule_id' => (string) $rule->id,
            'rule_code' => (string) $rule->rule_code,
            'version_no' => (int) $rule->version_no,
            'filed_by_seat' => (string) $seat->id,
        ];
    }

    /** The actor's SEATED seat on this department's board (R-18), or throw. */
    private function seatFor(?User $actor, Department $department): BoardSeat
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'F-BOG-001 is filed by a seated board member — system filings name no seat.',
                'Art. III §4 · §6'
            );
        }

        $seat = BoardSeat::query()
            ->where('board_id', (string) $department->board_id)
            ->where('holder_user_id', (string) $actor->getKey())
            ->where('status', BoardSeat::STATUS_SEATED)
            ->first();

        if ($seat === null) {
            throw new ConstitutionalViolation(
                'F-BOG-001 is filed by a seated member of THIS department\'s board (R-18).',
                'Art. III §4 · §6'
            );
        }

        return $seat;
    }
}
