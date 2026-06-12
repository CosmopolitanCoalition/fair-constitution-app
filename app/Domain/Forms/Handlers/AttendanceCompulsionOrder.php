<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\LegislatureSession;
use App\Models\User;
use App\Services\SessionService;

/**
 * F-SPK-008 — Attendance Compulsion Order (R-10; WF-LEG-20).
 *
 * After a failed quorum count, the Speaker compels every still-absent
 * member: attendance rows flip to 'compelled' and the order publishes to
 * the public register. The re-count is a fresh F-SPK-003.
 */
class AttendanceCompulsionOrder implements FormHandler
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
        return 'session.compulsion';
    }

    public function requiredRoles(): array
    {
        return ['R-10'];
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

        if ($actor !== null) {
            $member = $this->currentMemberOf($actor, (string) $session->legislature_id);

            if ((string) $session->legislature->speaker_id !== (string) $member->id) {
                throw new ConstitutionalViolation('Only the Speaker issues a compulsion order.', 'Art. II §3');
            }
        }

        $compelled = $this->sessions->compelAttendance($session);

        return [
            'session_id'      => (string) $session->id,
            'session_no'      => $session->session_no,
            'compelled'       => $compelled,
            'jurisdiction_id' => (string) $session->legislature->jurisdiction_id,
        ];
    }
}
