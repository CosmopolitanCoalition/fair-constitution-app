<?php

namespace App\Support;

use App\Models\Election;

/**
 * Server-side phase derivation (PHASE_B_DESIGN_frontend.md §B conventions):
 * the frozen scenario vocabulary `approval | ranked | certifying` maps 1:1
 * onto ESM-03 statuses so pages never re-derive it client-side.
 *
 *   scheduled | approval_open | finalist_cutoff → 'approval'
 *       (the cutoff transition is instant; 'scheduled' precedes the open
 *        window and renders the same approval-side chrome)
 *   ranked_open                                 → 'ranked'
 *   voting_closed | tabulating | certified |
 *   audit_rerun | final | cancelled             → 'certifying'
 */
final class ElectionPhase
{
    private function __construct()
    {
    }

    public static function phase(Election $election): string
    {
        return match ($election->status) {
            Election::STATUS_SCHEDULED,
            Election::STATUS_APPROVAL_OPEN,
            Election::STATUS_FINALIST_CUTOFF => 'approval',
            Election::STATUS_RANKED_OPEN     => 'ranked',
            default                          => 'certifying',
        };
    }

    /** Certifying sub-step badge: 'tabulating' | 'certified' | 'recount' | null. */
    public static function certSubStep(Election $election): ?string
    {
        return match ($election->status) {
            Election::STATUS_VOTING_CLOSED,
            Election::STATUS_TABULATING  => 'tabulating',
            Election::STATUS_CERTIFIED,
            Election::STATUS_FINAL       => 'certified',
            Election::STATUS_AUDIT_RERUN => 'recount',
            default                      => null,
        };
    }

    /** The linear ESM-03 strip pages render (audit_rerun rides certSubStep). */
    public static function machine(): array
    {
        return [
            Election::STATUS_SCHEDULED,
            Election::STATUS_APPROVAL_OPEN,
            Election::STATUS_FINALIST_CUTOFF,
            Election::STATUS_RANKED_OPEN,
            Election::STATUS_VOTING_CLOSED,
            Election::STATUS_TABULATING,
            Election::STATUS_CERTIFIED,
            Election::STATUS_FINAL,
        ];
    }
}
