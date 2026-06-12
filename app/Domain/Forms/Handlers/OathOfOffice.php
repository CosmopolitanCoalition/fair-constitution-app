<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\PublicRecordService;
use App\Services\RoleService;

/**
 * F-LEG-001 — Oath of Office / Seating Acceptance (chamber ops §A.6).
 *
 * Flips the actor's OWN member row `elected → seated` and stamps
 * `seated_at` — closing the gap the Phase B evolve migration documented
 * ("the Phase C oath flips to 'seated'"). First-session business order:
 * oath then speaker balloting (dependency chain 3.1 → 3.2). Publishes a
 * `participation` record.
 */
class OathOfOffice implements FormHandler
{
    public function __construct(
        private readonly PublicRecordService $records,
        private readonly RoleService $roles,
    ) {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'member.seated';
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
        $legislature = ChamberActor::legislature($payload, 'F-LEG-001');
        $member      = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-001');

        if ($member->status !== LegislatureMember::STATUS_ELECTED) {
            throw new ConstitutionalViolation(
                "The oath seats an ELECTED member (status: {$member->status}).",
                'Art. II §2 · as implemented'
            );
        }

        $member->forceFill([
            'status'    => LegislatureMember::STATUS_SEATED,
            'seated_at' => now(),
        ])->save();

        $this->records->publish(
            kind: 'participation',
            title: 'Oath of office — member seated',
            body: sprintf('Member %s took the oath and is seated (seat %s).', (string) $member->id, $member->seat_no),
            attrs: [
                'actor_user_id'   => (string) $member->user_id,
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-LEG-001',
                'subject_type'    => 'legislature_members',
                'subject_id'      => (string) $member->id,
            ],
        );

        $this->roles->flushUser((string) $member->user_id);

        return [
            'legislature_id' => (string) $legislature->id,
            'member_id'      => (string) $member->id,
            'status'         => $member->status,
            'seated_at'      => (string) $member->seated_at,
        ];
    }
}
