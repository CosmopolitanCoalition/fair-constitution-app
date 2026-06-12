<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\ConstitutionalValidator;
use App\Services\VacancyService;

/**
 * F-LEG-036 — Vacancy Declaration (chamber ops §F.1) — closes the Phase B
 * `vacancy:declare` dev gap: THE constitutional trigger into the ESM-13
 * machinery (countback → certify-or-special; Art. II §5).
 *
 * Declarer rule (PROTECTED, vacancy.declarer — Art. II §5 · as
 * implemented): the Speaker or the SYSTEM may declare any current seat;
 * a plain legislator only their OWN (resignation) — declaration is never
 * a weapon. Payload: `{member_id, reason ∈ (resigned, deceased, removed,
 * relocation, other), member_status? (removal proceedings record
 * 'removed')}`.
 */
class VacancyDeclaration implements FormHandler
{
    public const REASONS = ['resigned', 'deceased', 'removed', 'relocation', 'other'];

    public function __construct(
        private readonly VacancyService $vacancies,
    ) {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'vacancy.declared';
    }

    public function requiredRoles(): array
    {
        return ['R-09', 'R-10'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $member = LegislatureMember::query()->find($payload['member_id'] ?? null);

        if ($member === null) {
            throw new ConstitutionalViolation('F-LEG-036 requires a valid member_id.', 'Art. II §5');
        }

        $reason = (string) ($payload['reason'] ?? 'resigned');

        if (! in_array($reason, self::REASONS, true)) {
            throw new ConstitutionalViolation(
                'Vacancy reason must be one of: ' . implode(', ', self::REASONS) . '.',
                'Art. II §5 · as implemented'
            );
        }

        $legislature = Legislature::query()->findOrFail($member->legislature_id);

        $isSystem  = $actor === null;
        $isSpeaker = false;
        $ownSeat   = false;

        if (! $isSystem) {
            $actorMember = LegislatureMember::query()
                ->where('legislature_id', $legislature->id)
                ->where('user_id', (string) $actor->getKey())
                ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
                ->first();

            $isSpeaker = $actorMember !== null
                && $legislature->speaker_id !== null
                && (string) $legislature->speaker_id === (string) $actorMember->id;

            $ownSeat = $actorMember !== null && (string) $actorMember->id === (string) $member->id;
        }

        // PROTECTED rule: declaration is never a weapon (Art. II §5 · a.i.).
        ConstitutionalValidator::assertVacancyDeclarer($isSystem, $isSpeaker, $ownSeat);

        $memberStatus = (string) ($payload['member_status'] ?? LegislatureMember::STATUS_VACATED);

        $vacancy = $this->vacancies->declare(
            $member,
            $reason,
            $actor,
            via: 'F-LEG-036',
            queueCountback: true,
            memberStatus: $memberStatus,
        );

        return [
            'vacancy_id'     => (string) $vacancy->id,
            'member_id'      => (string) $member->id,
            'legislature_id' => (string) $legislature->id,
            'reason'         => $reason,
            'member_status'  => $memberStatus,
            'declared_by'    => $isSystem ? 'system' : ($isSpeaker ? 'speaker' : 'self'),
        ];
    }
}
