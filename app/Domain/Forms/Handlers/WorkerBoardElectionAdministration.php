<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Board;
use App\Models\Election;
use App\Models\User;
use App\Services\Organizations\OrgBoardElectionService;
use App\Services\Organizations\OrgBoardSeatingService;

/**
 * F-ORG-004 — Worker Board Election Administration (R-23 OR the
 * WF-ORG-04 SYSTEM auto-trigger — the Phase D exit criterion: a missing
 * or stalling agent can never block constitutionally-required worker
 * seats; CoDeterminationService files this form with the system actor).
 *
 * Actions: open_worker_election (§C.2), certify.
 */
class WorkerBoardElectionAdministration implements FormHandler
{
    public function __construct(
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
        return 'worker_board_election.administered';
    }

    public function requiredRoles(): array
    {
        return ['R-23'];
    }

    /** R-23 files it; null-actor (system) filings ride the engine bypass. */
    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $board = Board::query()->find($payload['board_id'] ?? null);

        if ($board === null) {
            throw new ConstitutionalViolation('F-ORG-004 targets an unknown board.', 'CGA Forms Catalog (F-ORG-004)');
        }

        // R-23 filings must come from the governed org's agent; system
        // filings (WF-ORG-04 auto-trigger) pass.
        if ($actor !== null) {
            $org = $board->organization();

            if ($org === null || (string) $org->agent_user_id !== (string) $actor->getKey()) {
                throw new ConstitutionalViolation(
                    'Only the governed organization\'s agent (or the system) administers worker-track elections.',
                    'CGA Forms Catalog (R-23)'
                );
            }
        }

        $action = (string) ($payload['action'] ?? '');

        return ['action' => $action, 'board_id' => (string) $board->id] + match ($action) {
            'open_worker_election' => (function () use ($board, $payload) {
                $election = $this->elections->openWorkerElection(
                    $board,
                    isset($payload['seats']) ? (int) $payload['seats'] : null,
                );

                return [
                    'election_id'     => (string) $election->id,
                    'kind'            => (string) $election->kind,
                    'electorate_type' => 'workers',
                ];
            })(),

            'certify' => (function () use ($payload) {
                $election = Election::query()->find($payload['election_id'] ?? null);

                if ($election === null) {
                    throw new ConstitutionalViolation('certify names an unknown election.', 'CGA Forms Catalog (F-ORG-004)');
                }

                return $this->seating->certify($election);
            })(),

            default => throw new ConstitutionalViolation(
                "Unknown F-ORG-004 action [{$action}].",
                'CGA Forms Catalog (F-ORG-004)'
            ),
        };
    }
}
