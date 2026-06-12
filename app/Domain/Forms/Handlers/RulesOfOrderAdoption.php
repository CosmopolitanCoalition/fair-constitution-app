<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\ChamberVoteProposal;
use App\Models\User;
use App\Services\Legislature\ChamberActService;

/**
 * F-LEG-032 — Rules of Order Adoption (chamber ops §D.2).
 *
 * DECISION: rules are LAWS (binding, challengeable under Art. IV §5,
 * versioned) — adopted by ORDINARY MAJORITY through the direct-adoption
 * path (no bill pipeline: first sessions precede committees). On
 * adoption the vote engine dispatches EnactmentService::enactDirect
 * (kind 'rules_of_order', act number allocated, law_versions v1);
 * re-adoption appends a version, never edits.
 */
class RulesOfOrderAdoption implements FormHandler
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
        return 'law.rules_of_order_proposed';
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
        $legislature = ChamberActor::legislature($payload, 'F-LEG-032');
        $member      = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-032');

        $result = $this->acts->proposeDirectLaw(
            $legislature,
            $member,
            ChamberVoteProposal::KIND_RULES_OF_ORDER,
            (string) ($payload['title'] ?? ''),
            (string) ($payload['text'] ?? ''),
        );

        return [
            'legislature_id' => (string) $legislature->id,
            'proposed_by'    => (string) $member->id,
            'title'          => (string) ($payload['title'] ?? ''),
        ] + $result;
    }
}
