<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesChairActor;
use App\Models\CommitteeMeeting;
use App\Models\User;
use App\Services\Legislature\CommitteeService;

/**
 * F-CHR-006 — Committee Meeting Adjournment + Minutes (chamber ops §C.5). The
 * chair (or acting alternate) adjourns an OPEN meeting and seals its minutes
 * into the append-only public record — the durable close of a hearing, the
 * committee analogue of the Speaker's F-SPK-009.
 */
class CommitteeMeetingAdjourn implements FormHandler
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
        return 'committee.meeting_adjourned';
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
            throw new ConstitutionalViolation('F-CHR-006 requires a valid meeting_id.', 'CGA Forms Catalog');
        }

        $committee = $meeting->committee()->firstOrFail();
        $chair     = $this->chairActor($actor, $committee, ['committee_id' => $committee->id] + $payload, 'F-CHR-006');

        $meeting = $this->committees->adjournMeeting(
            meeting: $meeting,
            minutesBody: (string) ($payload['minutes_body'] ?? ''),
            minutesTitle: isset($payload['minutes_title']) ? (string) $payload['minutes_title'] : null,
        );

        return [
            'committee_id'      => (string) $committee->id,
            'meeting_id'        => (string) $meeting->id,
            'adjourned_by'      => (string) $chair->id,
            'adjourned_at'      => (string) $meeting->adjourned_at,
            'minutes_record_id' => (string) $meeting->minutes_record_id,
            'status'            => $meeting->status,
        ];
    }
}
