<?php

namespace App\Domain\Forms\Support;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Election;
use App\Models\ElectionBoard;
use App\Models\ElectionBoardMember;
use App\Models\User;

/**
 * Board-member provenance shared by the F-ELB handlers: every board filing
 * (validation, certification) records WHICH seated member acted.
 *
 *  - human actor  → their SEATED election_board_members row on the
 *                   election's board (the R-08 role gate proves a seat on
 *                   SOME active board; the filing must come from THIS one).
 *  - system actor → the bootstrap board's synthetic member row
 *                   (user_id NULL — design §A B-2: the system itself, so
 *                   every F-ELB filing has board-member provenance without
 *                   inventing a fake user).
 */
class BoardProvenance
{
    /**
     * Resolve the election's board: the row it points at, else the active
     * board on the election's jurisdiction.
     */
    public static function boardFor(Election $election, string $formId): ElectionBoard
    {
        $board = $election->election_board_id !== null
            ? ElectionBoard::query()->find($election->election_board_id)
            : ElectionBoard::query()
                ->where('jurisdiction_id', $election->jurisdiction_id)
                ->active()
                ->first();

        if ($board === null) {
            throw new ConstitutionalViolation(
                "{$formId} requires an election board — none is active for this election's jurisdiction.",
                'CGA Forms Catalog (I-ELB)'
            );
        }

        return $board;
    }

    /**
     * Resolve a JURISDICTION's active board (Phase C, F-ELB-005 — the
     * petition audit has no election; the board responsible is the
     * petition jurisdiction's active board).
     */
    public static function boardForJurisdiction(string $jurisdictionId, string $formId): ElectionBoard
    {
        $board = ElectionBoard::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->active()
            ->first();

        if ($board === null) {
            throw new ConstitutionalViolation(
                "{$formId} requires an election board — none is active for this jurisdiction.",
                'CGA Forms Catalog (I-ELB)'
            );
        }

        return $board;
    }

    /**
     * Resolve the seated member row a filing against $board acts through
     * (shared by the election- and jurisdiction-anchored paths).
     */
    public static function resolveMemberOnBoard(?User $actor, ElectionBoard $board, string $formId): ElectionBoardMember
    {
        $member = $board->members()
            ->seated()
            ->when(
                $actor === null,
                fn ($q) => $q->whereNull('user_id'),                       // bootstrap system row
                fn ($q) => $q->where('user_id', (string) $actor->getKey()) // the actor's own seat
            )
            ->first();

        if ($member === null) {
            throw new ConstitutionalViolation(
                $actor === null
                    ? "{$formId} system filing requires the bootstrap board's synthetic member row — this board has none."
                    : "{$formId} must be filed by a seated member of the responsible board.",
                'CGA Forms Catalog (R-08)'
            );
        }

        return $member;
    }

    /**
     * Resolve the seated member row the filing acts through.
     */
    public static function resolveMember(?User $actor, Election $election, string $formId): ElectionBoardMember
    {
        return self::resolveMemberOnBoard($actor, self::boardFor($election, $formId), $formId);
    }
}
