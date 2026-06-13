<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\User;
use App\Services\Executive\ExecutiveActService;

/**
 * F-LEG-014 — Executive Committee Delegation Act (WF-EXE-01).
 *
 * Opens a SUPERMAJORITY chamber vote (vote_type exec_delegate) on a
 * proposal `{delegated_scope, member_count ≥ 5, interest[]}`. On
 * adoption the executive evolves forming → delegated and the committee
 * is composed from the chamber by the SAME proportional algorithm as
 * legislative committees (Art. III §1–2; CommitteeAssignmentService —
 * no parallel selection math).
 */
class ExecutiveDelegationAct implements FormHandler
{
    public function __construct(
        private readonly ExecutiveActService $acts,
    ) {
    }

    public function module(): string
    {
        return 'executive';
    }

    public function event(): string
    {
        return 'executive.delegation_proposed';
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
        $legislature = ChamberActor::legislature($payload, 'F-LEG-014');
        $member      = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-014');

        $result = $this->acts->proposeDelegation(
            $legislature,
            $member,
            (string) ($payload['delegated_scope'] ?? ''),
            (int) ($payload['member_count'] ?? 0),
            array_values((array) ($payload['interest'] ?? [])),
        );

        return [
            'legislature_id'  => (string) $legislature->id,
            'proposed_by'     => (string) $member->id,
            'member_count'    => (int) $payload['member_count'],
            'delegated_scope' => (string) $payload['delegated_scope'],
        ] + $result;
    }
}
