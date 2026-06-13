<?php

namespace App\Domain\Forms\Support;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Advocate;
use App\Models\CourtCase;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\User;

/**
 * Shared actor resolution for the Phase E cases-agent handlers: a F-JDG-*
 * filing acts THROUGH the actor's SEATED judicial seat on the case's
 * judiciary (the R-19/R-20 role gate proves a seat in SOME court; the filing
 * must come from THIS one). F-ADV-* / F-IND-017-advocate filings act through
 * the actor's registered advocate row.
 */
class JudicialActor
{
    /** The case named in the payload, or throw. */
    public static function case(array $payload, string $formId): CourtCase
    {
        $id = $payload['case_id'] ?? null;

        $case = is_string($id) ? CourtCase::query()->find($id) : null;

        if ($case === null) {
            throw new ConstitutionalViolation(
                "{$formId} requires a valid case_id.",
                'CGA Forms Catalog'
            );
        }

        return $case;
    }

    /** The judiciary named in the payload (or via the case), or throw. */
    public static function judiciary(array $payload, string $formId): Judiciary
    {
        $id = $payload['judiciary_id'] ?? null;

        $judiciary = is_string($id) ? Judiciary::query()->find($id) : null;

        if ($judiciary === null) {
            throw new ConstitutionalViolation(
                "{$formId} requires a valid judiciary_id.",
                'CGA Forms Catalog'
            );
        }

        return $judiciary;
    }

    /**
     * The actor's CURRENT seated judge seat on the given judiciary, or throw.
     * Either an appointed (R-19) or an elected (R-20) judge may act — the form
     * catalog lists both for every F-JDG form.
     */
    public static function seat(?User $actor, string $judiciaryId, string $formId): JudicialSeat
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                "{$formId} is filed by a serving judge — system filings name no judge.",
                'CGA Roles & Forms Chart'
            );
        }

        $seat = JudicialSeat::query()
            ->where('judiciary_id', $judiciaryId)
            ->where('user_id', (string) $actor->getKey())
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->whereNull('deleted_at')
            ->first();

        if ($seat === null) {
            throw new ConstitutionalViolation(
                "{$formId} must be filed by a SEATED judge of THIS court (Art. IV §4).",
                'CGA Roles & Forms Chart (R-19/R-20)'
            );
        }

        return $seat;
    }

    /**
     * The actor's registered advocate row at the given judiciary, or throw
     * (the F-ADV-* gate; the page explains, the engine gates).
     */
    public static function advocate(?User $actor, string $judiciaryId, string $formId): Advocate
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                "{$formId} is filed by a registered advocate — system filings name no advocate.",
                'CGA Roles & Forms Chart'
            );
        }

        $advocate = Advocate::query()
            ->where('user_id', (string) $actor->getKey())
            ->where('judiciary_id', $judiciaryId)
            ->where('status', Advocate::STATUS_REGISTERED)
            ->whereNull('deleted_at')
            ->first();

        if ($advocate === null) {
            throw new ConstitutionalViolation(
                "{$formId} must be filed by an advocate registered with THIS court — register first (F-IND-015).",
                'Art. IV §4'
            );
        }

        return $advocate;
    }
}
