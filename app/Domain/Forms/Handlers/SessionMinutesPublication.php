<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\LegislatureSession;
use App\Models\User;
use App\Services\SessionService;

/**
 * F-SPK-009 — Session Minutes Publication (R-10 | R-29; system allowed).
 *
 * Publishes the minutes (public_records kind 'minutes'), adjourns the
 * session, stamps legislatures.last_met_on / next_meeting_due_by, and
 * cancel+re-arms CLK-02 with the derivation anchor
 * {anchor_at: last_met_on, unit: 'days'} — SessionService::adjourn. A
 * failed-quorum session adjourns WITHOUT resetting the clock (the
 * chamber did not constitutionally meet; WF-LEG-20).
 *
 * R-29 (admin staff) filers are authorized by the chamber-ops admin
 * office substrate when it lands; until then a human filer must be the
 * Speaker (fact check below).
 */
class SessionMinutesPublication implements FormHandler
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
        return 'session.adjourned';
    }

    public function requiredRoles(): array
    {
        return ['R-10', 'R-29'];
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

        $minutes = trim((string) ($payload['minutes_body'] ?? ''));

        if ($minutes === '') {
            throw new ConstitutionalViolation('Minutes carry text (WF-SYS-03).', 'Art. II §2');
        }

        if ($actor !== null) {
            $member = $this->currentMemberOf($actor, (string) $session->legislature_id);

            if ((string) $session->legislature->speaker_id !== (string) $member->id) {
                throw new ConstitutionalViolation(
                    'Minutes are published by the Speaker (or admin office staff / the system).',
                    'Art. II §3 · as implemented'
                );
            }
        }

        $session = $this->sessions->adjourn(
            session: $session,
            minutesBody: $minutes,
            minutesTitle: isset($payload['minutes_title']) ? (string) $payload['minutes_title'] : null,
        );

        $legislature = $session->legislature->refresh();

        return [
            'session_id'          => (string) $session->id,
            'session_no'          => $session->session_no,
            'status'              => $session->status,
            'quorum_met'          => $session->quorum_met,
            'minutes_record_id'   => (string) $session->minutes_record_id,
            'last_met_on'         => $legislature->last_met_on?->toDateString(),
            'next_meeting_due_by' => $legislature->next_meeting_due_by?->toDateString(),
            'jurisdiction_id'     => (string) $legislature->jurisdiction_id,
        ];
    }
}
