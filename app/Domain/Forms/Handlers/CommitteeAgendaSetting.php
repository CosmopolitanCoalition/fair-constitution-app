<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesChairActor;
use App\Models\CommitteeMeeting;
use App\Models\User;

/**
 * F-CHR-002 — Committee Agenda Setting (chamber ops §C.5). The chair (or
 * acting alternate) sets/updates a scheduled or open meeting's agenda —
 * committee agendas have no engine-locked head (emergency review is a
 * FLOOR session duty, Art. II §2).
 */
class CommitteeAgendaSetting implements FormHandler
{
    use ResolvesChairActor;

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'committee.agenda_set';
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
            throw new ConstitutionalViolation('F-CHR-002 requires a valid meeting_id.', 'CGA Forms Catalog');
        }

        if (! in_array($meeting->status, [CommitteeMeeting::STATUS_SCHEDULED, CommitteeMeeting::STATUS_OPEN], true)) {
            throw new ConstitutionalViolation(
                "Agendas are set on scheduled/open meetings (status: {$meeting->status}).",
                'CGA Forms Catalog (F-CHR-002)'
            );
        }

        $committee = $meeting->committee()->firstOrFail();
        $chair     = $this->chairActor($actor, $committee, ['committee_id' => $committee->id] + $payload, 'F-CHR-002');

        $agenda = array_values((array) ($payload['agenda'] ?? []));

        $meeting->forceFill(['agenda' => $agenda])->save();

        return [
            'committee_id' => (string) $committee->id,
            'meeting_id'   => (string) $meeting->id,
            'set_by'       => (string) $chair->id,
            'agenda_size'  => count($agenda),
        ];
    }
}
