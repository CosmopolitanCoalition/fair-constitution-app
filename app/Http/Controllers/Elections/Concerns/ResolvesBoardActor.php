<?php

namespace App\Http\Controllers\Elections\Concerns;

use App\Models\ElectionBoard;
use App\Models\ElectionBoardMember;
use App\Models\User;

/**
 * Shared R-08 actor resolution for the board-facing controllers
 * (BoardConsole, Results, VacancyCountback).
 *
 * Two lawful postures (RoleService R-08 + BoardProvenance):
 *  - a human SEATED member of the board files as themselves
 *    (actor = $user; BoardProvenance resolves their member row);
 *  - the operator driving an active BOOTSTRAP board files as the system
 *    (actor = null; BoardProvenance resolves the synthetic user_id-NULL
 *    member row). The chain records actor null — honest: the system board
 *    acted, with the operator's hand on the dev tiller.
 */
trait ResolvesBoardActor
{
    /**
     * The engine actor for a filing against $board, or false when the
     * user holds no standing on it.
     *
     * @return array{actor: User|null}|false
     */
    protected function boardActorFor(?User $user, ?ElectionBoard $board): array|false
    {
        if ($user === null || $board === null) {
            return false;
        }

        $seated = ElectionBoardMember::query()
            ->where('election_board_id', (string) $board->id)
            ->seated()
            ->where('user_id', (string) $user->getKey())
            ->exists();

        if ($seated) {
            return ['actor' => $user];
        }

        if ($board->is_bootstrap && (bool) $user->is_operator && $board->status === ElectionBoard::STATUS_ACTIVE) {
            return ['actor' => null];
        }

        return false;
    }

    /** The active board responsible for a jurisdiction (may be null). */
    protected function activeBoardFor(?string $boardId, ?string $jurisdictionId): ?ElectionBoard
    {
        if ($boardId !== null) {
            $board = ElectionBoard::query()->find($boardId);

            if ($board !== null) {
                return $board;
            }
        }

        if ($jurisdictionId === null) {
            return null;
        }

        return ElectionBoard::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->active()
            ->first();
    }
}
