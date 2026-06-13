<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ExecutiveActor;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\Department;
use App\Models\User;
use App\Services\Executive\BoardGovernorService;

/**
 * F-EXE-003 — Board Member Removal Request (WF-EXE-06, owner ruling #14).
 *
 * Good-faith competence/ethics grounds published at filing; the seat →
 * removal_requested; the legislature decides at ORDINARY MAJORITY
 * (procedural_motion — deliberately NOT the supermajority
 * officeholder_remove machinery: governor removal is hiring-and-firing).
 */
class BoardMemberRemovalRequest implements FormHandler
{
    public function __construct(
        private readonly BoardGovernorService $governors,
    ) {
    }

    public function module(): string
    {
        return 'executive';
    }

    public function event(): string
    {
        return 'governor.removal_requested';
    }

    public function requiredRoles(): array
    {
        return ['R-14', 'R-15', 'R-16'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $seat = BoardSeat::query()->find((string) ($payload['board_seat_id'] ?? ''));

        if ($seat === null) {
            throw new ConstitutionalViolation('F-EXE-003 names a board seat.', 'Art. III §4');
        }

        $board = Board::query()->findOrFail((string) $seat->board_id);

        if ($board->boardable_type !== Board::BOARDABLE_DEPARTMENTS) {
            throw new ConstitutionalViolation(
                'F-EXE-003 runs against DEPARTMENT board seats — org boards remove through their own tracks.',
                'Art. III §4'
            );
        }

        $department = Department::query()->findOrFail((string) $board->boardable_id);

        $member = ExecutiveActor::member($actor, (string) $department->executive_id, 'F-EXE-003');

        $result = $this->governors->requestRemoval(
            $seat,
            $member,
            (string) ($payload['grounds'] ?? ''),
        );

        return [
            'board_seat_id' => (string) $seat->id,
            'department_id' => (string) $department->id,
            'requested_by'  => (string) $member->id,
        ] + $result;
    }
}
