<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\LegislatureMember;
use App\Models\LegislatureSession;
use App\Models\User;
use App\Services\SessionService;

/**
 * F-SPK-006 — Member Priority Communication Facilitation (chamber ops
 * §B.3): the Speaker files `{session_id, member_id, text}` — the
 * member's priority joins the session's general agenda tail (the agenda
 * jsonb has no dedicated member_priority kind in the C-2 enum; recorded
 * as a titled `general` item — documented) and the filing itself is the
 * priorities log.
 */
class MemberPriorityFacilitation implements FormHandler
{
    public function __construct(
        private readonly SessionService $sessions,
    ) {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'session.member_priority';
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
            throw new ConstitutionalViolation('F-SPK-006 requires a valid session_id.', 'CGA Forms Catalog');
        }

        $member = LegislatureMember::query()
            ->whereKey($payload['member_id'] ?? null)
            ->where('legislature_id', $session->legislature_id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->first();

        if ($member === null) {
            throw new ConstitutionalViolation(
                'Member priorities are facilitated for currently serving members of this chamber.',
                'Art. II §3'
            );
        }

        $text = trim((string) ($payload['text'] ?? ''));

        if ($text === '') {
            throw new ConstitutionalViolation('A member priority carries text.', 'CGA Forms Catalog (F-SPK-006)');
        }

        if ($actor !== null) {
            $legislature = $session->legislature;

            \App\Domain\Forms\Support\ChamberActor::speaker($actor, $legislature, 'F-SPK-006');
        }

        // Append to the unlocked tail (setAgenda enforces the locked head).
        $existingTail = array_values(array_filter(
            $session->agenda ?? [],
            fn ($item) => ! (bool) (((array) $item)['locked'] ?? false)
        ));

        $existingTail[] = [
            'kind'     => 'general',
            'ref_type' => 'legislature_members',
            'ref_id'   => (string) $member->id,
            'title'    => 'Member priority — ' . mb_substr($text, 0, 120),
        ];

        $agenda = $this->sessions->setAgenda($session, $existingTail);

        return [
            'session_id'  => (string) $session->id,
            'member_id'   => (string) $member->id,
            'text'        => $text,
            'agenda_size' => count($agenda),
        ];
    }
}
