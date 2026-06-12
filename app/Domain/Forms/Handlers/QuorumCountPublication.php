<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\LegislatureSession;
use App\Models\User;
use App\Services\SessionService;

/**
 * F-SPK-003 — Quorum Count Publication (R-10; system filings allowed —
 * first sessions run the count engine-side before a Speaker exists).
 *
 * Snapshots present vs quorum_required (per kind when bicameral — each
 * kind must meet its OWN peg quorum, q-ledger #q7 extended) and
 * publishes the count. Not met → failed_quorum, the WF-LEG-20 branch
 * (F-SPK-008 compulsion).
 */
class QuorumCountPublication implements FormHandler
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
        return 'session.quorum';
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
                throw new ConstitutionalViolation('Only the Speaker publishes the quorum count.', 'Art. II §3');
            }
        }

        $count = $this->sessions->publishQuorumCount($session);

        return [
            'session_id'      => (string) $session->id,
            'session_no'      => $session->session_no,
            'jurisdiction_id' => (string) $session->legislature->jurisdiction_id,
        ] + $count;
    }
}
