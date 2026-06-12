<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\LegislatureSession;
use App\Models\User;
use App\Services\SessionService;

/**
 * F-LEG-007 — Motion Submission (R-09). ESM-08.
 *
 * Creates the motion and opens its procedural_motion chamber vote in the
 * same filing (majority of ALL serving — the unstated-threshold owner
 * ruling). Referral kinds must name a bill. The vote's adoption applies
 * the ESM-08 consequence (direct_to_floor → bill on_floor + floor vote,
 * referral, table, amendment version) inside the closing transaction.
 */
class MotionSubmission implements FormHandler
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
        return 'motion.submitted';
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
        $session = LegislatureSession::query()->find($payload['session_id'] ?? null);

        if ($session === null) {
            throw new ConstitutionalViolation('Unknown session.', 'Art. II §2 · as implemented');
        }

        $member = $this->currentMemberOf($actor, (string) $session->legislature_id);

        $motion = $this->sessions->submitMotion(
            session: $session,
            movedBy: $member,
            kind: (string) ($payload['kind'] ?? ''),
            text: (string) ($payload['text'] ?? ''),
            billId: isset($payload['bill_id']) ? (string) $payload['bill_id'] : null,
            amendmentText: isset($payload['amendment_text']) ? (string) $payload['amendment_text'] : null,
        );

        return [
            'motion_id'       => (string) $motion->id,
            'session_id'      => (string) $session->id,
            'kind'            => $motion->kind,
            'bill_id'         => $motion->bill_id !== null ? (string) $motion->bill_id : null,
            'vote_id'         => $motion->vote_id !== null ? (string) $motion->vote_id : null,
            'moved_by'        => (string) $member->id,
            'status'          => $motion->status,
            'jurisdiction_id' => (string) $session->legislature->jurisdiction_id,
        ];
    }
}
