<?php

namespace App\Domain\Forms\Support;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\User;

/**
 * Shared executive-actor resolution for the Phase D F-EXE handlers: a
 * filing acts THROUGH the actor's seated member row of the named
 * executive (the R-14/15/16 role gates prove a seat on SOME executive;
 * the filing must come from THIS one) — the ChamberActor pattern.
 */
class ExecutiveActor
{
    /** The actor's SEATED member row on the executive, or throw. */
    public static function member(?User $actor, string $executiveId, string $formId): ExecutiveMember
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                "{$formId} is filed by a seated executive member — system filings name no member.",
                'CGA Roles & Forms Chart'
            );
        }

        $member = ExecutiveMember::query()
            ->where('executive_id', $executiveId)
            ->where('user_id', (string) $actor->getKey())
            ->where('status', ExecutiveMember::STATUS_SEATED)
            ->first();

        if ($member === null) {
            throw new ConstitutionalViolation(
                "{$formId} must be filed by a seated member of THIS executive.",
                'CGA Roles & Forms Chart (R-14/R-15/R-16)'
            );
        }

        return $member;
    }

    /** The executive named in the payload, or throw. */
    public static function executive(array $payload, string $formId): Executive
    {
        $id = $payload['executive_id'] ?? null;

        $executive = is_string($id) ? Executive::query()->find($id) : null;

        if ($executive === null) {
            throw new ConstitutionalViolation(
                "{$formId} requires a valid executive_id.",
                'CGA Forms Catalog'
            );
        }

        return $executive;
    }
}
