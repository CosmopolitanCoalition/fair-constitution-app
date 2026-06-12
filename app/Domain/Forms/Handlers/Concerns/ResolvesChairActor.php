<?php

namespace App\Domain\Forms\Handlers\Concerns;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Committee;
use App\Models\LegislatureMember;
use App\Models\User;

/**
 * Shared F-CHR actor resolution (chamber ops §C.5): the committee's CHAIR
 * (R-12) files; the ALTERNATE (R-13) files when the chair is absent —
 * attested by `chair_unavailable: true` on the filing (recorded; the
 * live-meeting attendance check refines this when committee attendance
 * lands with meetings tooling).
 */
trait ResolvesChairActor
{
    protected function chairActor(?User $actor, Committee $committee, array $payload, string $formId): LegislatureMember
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                "{$formId} is filed by the committee chair (or the alternate when the chair is absent).",
                'CGA Roles & Forms Chart (R-12/R-13)'
            );
        }

        $member = LegislatureMember::query()
            ->where('legislature_id', $committee->legislature_id)
            ->where('user_id', (string) $actor->getKey())
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->first();

        if ($member === null) {
            throw new ConstitutionalViolation(
                "{$formId} must be filed by a currently serving member of the committee's chamber.",
                'CGA Roles & Forms Chart (R-09)'
            );
        }

        $isChair = $committee->chair_member_id !== null
            && (string) $committee->chair_member_id === (string) $member->id;

        if ($isChair) {
            return $member;
        }

        $isAlternate = $committee->alternate_member_id !== null
            && (string) $committee->alternate_member_id === (string) $member->id;

        if ($isAlternate) {
            if (! (bool) ($payload['chair_unavailable'] ?? false)) {
                throw new ConstitutionalViolation(
                    'The alternate acts only when the chair is absent — attest chair_unavailable on the filing.',
                    'CGA Roles & Forms Chart (R-13) · as implemented'
                );
            }

            return $member;
        }

        throw new ConstitutionalViolation(
            "{$formId} is filed by THIS committee's chair or alternate.",
            'CGA Roles & Forms Chart (R-12/R-13)'
        );
    }

    protected function committeeFrom(array $payload, string $formId): Committee
    {
        $committee = Committee::query()->find($payload['committee_id'] ?? null);

        if ($committee === null) {
            throw new ConstitutionalViolation("{$formId} requires a valid committee_id.", 'CGA Forms Catalog');
        }

        return $committee;
    }
}
