<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\Legislature;
use App\Models\User;
use App\Services\PublicRecordService;

/**
 * F-LEG-006 — Public Record Statement (R-09, WF-SYS-03).
 *
 * A member publishes a statement to the public register, optionally
 * attached to a subject (bill / session / vote) via subject_type +
 * subject_id.
 */
class PublicRecordStatement implements FormHandler
{
    use ResolvesLegislativeActor;

    public function __construct(private readonly PublicRecordService $records)
    {
    }

    public function module(): string
    {
        return 'records';
    }

    public function event(): string
    {
        return 'statement.published';
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
        $body = trim((string) ($payload['body'] ?? ''));

        if ($body === '') {
            throw new ConstitutionalViolation('A statement carries text.', 'Art. II §2 · as implemented');
        }

        $legislature = Legislature::query()->find($payload['legislature_id'] ?? null);

        if ($legislature === null) {
            throw new ConstitutionalViolation('Unknown legislature.', 'Art. II §2 · as implemented');
        }

        $member = $this->currentMemberOf($actor, (string) $legislature->id);

        $record = $this->records->publish(
            kind: 'statement',
            title: (string) ($payload['title'] ?? ('Statement — ' . now()->toDateString())),
            body: $body,
            attrs: [
                'actor_user_id'   => (string) $actor->getKey(),
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-LEG-006',
                'subject_type'    => isset($payload['subject_type']) ? (string) $payload['subject_type'] : null,
                'subject_id'      => isset($payload['subject_id']) ? (string) $payload['subject_id'] : null,
            ],
        );

        return [
            'record_seq'      => (int) $record->seq,
            'record_id'       => (string) $record->id,
            'member_id'       => (string) $member->id,
            'title'           => $record->title,
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
        ];
    }
}
