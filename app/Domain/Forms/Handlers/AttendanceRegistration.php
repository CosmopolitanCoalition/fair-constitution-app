<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\LegislatureSession;
use App\Models\User;
use App\Services\SessionService;

/**
 * F-LEG-002 — Attendance Registration (R-09).
 *
 * The member flips their own attendance row to 'present' on an open
 * session. Attendance feeds the quorum CALL and the public record only —
 * never a vote denominator (hardened framing, PHASE_C_DESIGN_votes_laws
 * §A C-2).
 */
class AttendanceRegistration implements FormHandler
{
    use ResolvesLegislativeActor;

    public function __construct(private readonly SessionService $sessions)
    {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'session.attendance';
    }

    public function requiredRoles(): array
    {
        return ['R-09'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $session = LegislatureSession::query()->find($payload['session_id'] ?? null);

        if ($session === null) {
            throw new ConstitutionalViolation('Unknown session.', 'Art. II §2 · as implemented');
        }

        $member = $this->currentMemberOf($actor, (string) $session->legislature_id);

        $row = $this->sessions->registerAttendance($session, $member);

        return [
            'session_id'      => (string) $session->id,
            'session_no'      => $session->session_no,
            'member_id'       => (string) $member->id,
            'status'          => $row->status,
            'jurisdiction_id' => (string) $session->legislature->jurisdiction_id,
        ];
    }
}
