<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesChairActor;
use App\Models\CommitteeMeeting;
use App\Models\User;
use App\Services\Legislature\CommitteeService;

/**
 * F-CHR-005 — Committee Meeting Open (chamber ops §C.5). The chair (or the
 * acting alternate when the chair is absent) gavels a SCHEDULED meeting open.
 * A committee meeting is CALLED ahead of time (F-CHR-001, scheduled) and then
 * OPENED here — the point at which the hearing room's floor becomes live and
 * an agenda item can be taken up (LiveRoomController::agendaItems).
 */
class CommitteeMeetingOpen implements FormHandler
{
    use ResolvesChairActor;

    public function __construct(private readonly CommitteeService $committees)
    {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'committee.meeting_opened';
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
        $meeting = CommitteeMeeting::query()->find($payload['meeting_id'] ?? null);

        if ($meeting === null) {
            throw new ConstitutionalViolation('F-CHR-005 requires a valid meeting_id.', 'CGA Forms Catalog');
        }

        $committee = $meeting->committee()->firstOrFail();
        $chair     = $this->chairActor($actor, $committee, ['committee_id' => $committee->id] + $payload, 'F-CHR-005');

        $meeting = $this->committees->openMeeting($meeting);

        return [
            'committee_id' => (string) $committee->id,
            'meeting_id'   => (string) $meeting->id,
            'opened_by'    => (string) $chair->id,
            'opened_at'    => (string) $meeting->opened_at,
            'status'       => $meeting->status,
        ];
    }
}
