<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\User;
use App\Services\Legislature\ChamberActService;

/**
 * F-LEG-012 — Election Board Creation Act (chamber ops §E.1, WF-ELE-10).
 *
 * SUPERMAJORITY chamber vote on `{jurisdiction_id (= the legislature's),
 * nominees: [user ids] (optional at filing)}`. On adoption: proper board
 * `forming` (is_bootstrap = false) + one appointment-consent vote per
 * nominee (ordinary majority — unstated threshold rule). Nominee
 * eligibility: active association in the jurisdiction (Art. I — the only
 * check; independence is a duty of the office, not a test). The bootstrap
 * board retires automatically when the proper board reaches readiness
 * (ElectionBoardTransitionService — custody of in-flight elections
 * transfers, certified history keeps its provenance).
 */
class ElectionBoardCreationAct implements FormHandler
{
    public function __construct(
        private readonly ChamberActService $acts,
    ) {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'election_board.creation_proposed';
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
        $legislature = ChamberActor::legislature($payload, 'F-LEG-012');
        $member      = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-012');

        $result = $this->acts->proposeElectionBoard(
            $legislature,
            $member,
            (string) ($payload['jurisdiction_id'] ?? ''),
            (array) ($payload['nominees'] ?? []),
        );

        return [
            'legislature_id' => (string) $legislature->id,
            'proposed_by'    => (string) $member->id,
            'nominees'       => array_values(array_map('strval', (array) ($payload['nominees'] ?? []))),
        ] + $result;
    }
}
