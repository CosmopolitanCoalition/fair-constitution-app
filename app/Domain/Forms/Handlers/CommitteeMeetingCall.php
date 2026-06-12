<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesChairActor;
use App\Models\Committee;
use App\Models\CommitteeMeeting;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * F-CHR-001 — Committee Meeting Call (chamber ops §C.5). The chair (or
 * the alternate when the chair is absent) schedules a committee meeting.
 */
class CommitteeMeetingCall implements FormHandler
{
    use ResolvesChairActor;

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'committee.meeting_called';
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
        $committee = $this->committeeFrom($payload, 'F-CHR-001');

        if ($committee->status !== Committee::STATUS_SEATED) {
            throw new ConstitutionalViolation(
                'Meetings run on SEATED committees.',
                'CGA Forms Catalog (F-CHR-001)'
            );
        }

        $chair = $this->chairActor($actor, $committee, $payload, 'F-CHR-001');

        $meeting = CommitteeMeeting::create([
            'committee_id'        => $committee->id,
            'called_by_member_id' => $chair->id,
            'scheduled_for'       => isset($payload['scheduled_for'])
                ? CarbonImmutable::parse((string) $payload['scheduled_for'])
                : now(),
            'agenda'              => array_values((array) ($payload['agenda'] ?? [])),
            'status'              => CommitteeMeeting::STATUS_SCHEDULED,
        ]);

        return [
            'committee_id'      => (string) $committee->id,
            'meeting_id'        => (string) $meeting->id,
            'called_by'         => (string) $chair->id,
            'scheduled_for'     => (string) $meeting->scheduled_for,
            'chair_unavailable' => (bool) ($payload['chair_unavailable'] ?? false),
        ];
    }
}
