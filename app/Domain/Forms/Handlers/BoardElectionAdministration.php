<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Board;
use App\Models\Election;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrgBoardElectionService;
use App\Services\Organizations\OrgBoardSeatingService;
use App\Services\Organizations\OrgBoardService;

/**
 * F-ORG-003 — Board Election Administration (R-23; owner track).
 *
 * Actions: provision_board (first board: owner seats in [1,99],
 * cycle_months; equal_partnership convention: owner_seats defaults to
 * the active partner count), open_owner_election (§C.2), certify.
 */
class BoardElectionAdministration implements FormHandler
{
    public function __construct(
        private readonly OrgBoardService $boards,
        private readonly OrgBoardElectionService $elections,
        private readonly OrgBoardSeatingService $seating,
    ) {
    }

    public function module(): string
    {
        return 'organizations';
    }

    public function event(): string
    {
        return 'board_election.administered';
    }

    public function requiredRoles(): array
    {
        return ['R-23'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $org = Organization::query()->find($payload['organization_id'] ?? null);

        if ($org === null) {
            throw new ConstitutionalViolation('F-ORG-003 targets an unknown organization.', 'CGA Forms Catalog (F-ORG-003)');
        }

        if ($actor !== null && (string) $org->agent_user_id !== (string) $actor->getKey()) {
            throw new ConstitutionalViolation(
                'Only this organization\'s agent administers its board elections (R-23).',
                'CGA Forms Catalog (R-23)'
            );
        }

        $action = (string) ($payload['action'] ?? '');

        return ['action' => $action, 'organization_id' => (string) $org->id] + match ($action) {
            'provision_board' => (function () use ($org, $payload) {
                $ownerSeats = (int) ($payload['owner_seats'] ?? 0);

                // Equal partnerships seat every partner by convention.
                if ($ownerSeats === 0 && $org->structure === Organization::STRUCTURE_EQUAL_PARTNERSHIP) {
                    $ownerSeats = $org->memberships()->active()->where('kind', 'partner')->count();
                }

                $board = $this->boards->provision(
                    $org,
                    $ownerSeats,
                    isset($payload['cycle_months']) ? (int) $payload['cycle_months'] : null,
                );

                return [
                    'board_id'     => (string) $board->id,
                    'owner_seats'  => (int) $board->owner_seats,
                    'cycle_months' => (int) $board->cycle_months,
                ];
            })(),

            'open_owner_election' => (function () use ($org) {
                $board = $this->requireBoard($org);

                $election = $this->elections->openOwnerElection($board);

                return ['election_id' => (string) $election->id, 'kind' => (string) $election->kind];
            })(),

            'certify' => (function () use ($payload) {
                $election = Election::query()->find($payload['election_id'] ?? null);

                if ($election === null) {
                    throw new ConstitutionalViolation('certify names an unknown election.', 'CGA Forms Catalog (F-ORG-003)');
                }

                return $this->seating->certify($election);
            })(),

            default => throw new ConstitutionalViolation(
                "Unknown F-ORG-003 action [{$action}].",
                'CGA Forms Catalog (F-ORG-003)'
            ),
        };
    }

    private function requireBoard(Organization $org): Board
    {
        $board = $org->board_id !== null ? Board::query()->find($org->board_id) : null;

        if ($board === null) {
            throw new ConstitutionalViolation(
                'This organization has no board — provision_board first.',
                'CGA Forms Catalog (F-ORG-003)'
            );
        }

        return $board;
    }
}
