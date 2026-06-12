<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\LegislatureSession;
use App\Models\User;
use App\Services\SessionService;

/**
 * F-SPK-002 — Agenda Setting (R-10).
 *
 * The Speaker orders the unlocked agenda tail and acknowledges locked
 * slot-1 items (mark_addressed_ref_id). Locked slots are immutable to
 * filings — the head is engine-composed (Art. II §2 order of business:
 * emergency powers → constitutional matters → general).
 */
class AgendaSetting implements FormHandler
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
        return 'session.agenda';
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

        $this->assertSpeakerOrSystem($actor, $session);

        $agenda = $this->sessions->setAgenda(
            session: $session,
            tail: is_array($payload['items'] ?? null) ? $payload['items'] : [],
            addressRefId: isset($payload['mark_addressed_ref_id']) ? (string) $payload['mark_addressed_ref_id'] : null,
        );

        return [
            'session_id'      => (string) $session->id,
            'agenda'          => $agenda,
            'jurisdiction_id' => (string) $session->legislature->jurisdiction_id,
        ];
    }

    private function assertSpeakerOrSystem(?User $actor, LegislatureSession $session): void
    {
        if ($actor === null) {
            return;
        }

        $member = $this->currentMemberOf($actor, (string) $session->legislature_id);

        if ((string) $session->legislature->speaker_id !== (string) $member->id) {
            throw new ConstitutionalViolation('Only the Speaker sets the agenda.', 'Art. II §3');
        }
    }
}
