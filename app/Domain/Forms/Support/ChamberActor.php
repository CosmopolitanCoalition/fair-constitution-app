<?php

namespace App\Domain\Forms\Support;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;

/**
 * Shared chamber-actor resolution for the Phase C chamber-ops handlers:
 * a filing acts THROUGH the actor's current member row of the named
 * chamber (the R-09/R-10 role gates prove a seat in SOME chamber; the
 * filing must come from THIS one).
 */
class ChamberActor
{
    /** The actor's CURRENT member row in the legislature, or throw. */
    public static function member(?User $actor, string $legislatureId, string $formId): LegislatureMember
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                "{$formId} is filed by a serving member — system filings name no member.",
                'CGA Roles & Forms Chart'
            );
        }

        $member = LegislatureMember::query()
            ->where('legislature_id', $legislatureId)
            ->where('user_id', (string) $actor->getKey())
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->first();

        if ($member === null) {
            throw new ConstitutionalViolation(
                "{$formId} must be filed by a currently serving member of THIS chamber.",
                'CGA Roles & Forms Chart (R-09)'
            );
        }

        return $member;
    }

    /**
     * The actor's member row, additionally required to BE the chamber's
     * Speaker (authoritative fact: legislatures.speaker_id).
     */
    public static function speaker(?User $actor, Legislature $legislature, string $formId): LegislatureMember
    {
        $member = self::member($actor, (string) $legislature->id, $formId);

        if ($legislature->speaker_id === null || (string) $legislature->speaker_id !== (string) $member->id) {
            throw new ConstitutionalViolation(
                "{$formId} is filed by the Speaker of THIS chamber (R-10).",
                'Art. II §3'
            );
        }

        return $member;
    }

    /** The legislature named in the payload, or throw. */
    public static function legislature(array $payload, string $formId): Legislature
    {
        $id = $payload['legislature_id'] ?? null;

        $legislature = is_string($id) ? Legislature::query()->find($id) : null;

        if ($legislature === null) {
            throw new ConstitutionalViolation(
                "{$formId} requires a valid legislature_id.",
                'CGA Forms Catalog'
            );
        }

        return $legislature;
    }
}
