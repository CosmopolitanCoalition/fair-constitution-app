<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\User;
use App\Services\Legislature\ChamberActService;

/**
 * F-LEG-029 — Union Formation/Join Vote (R-09; Art. V §7). The initiating
 * chamber supermajority OPENS, on adoption, the dual-meter ratification: an
 * applicant population referendum AND a constituent-jurisdiction supermajority
 * MultiJurisdictionVote. BOTH must pass before the union change takes effect.
 */
class UnionFormationJoinVote implements FormHandler
{
    public function __construct(private readonly ChamberActService $acts) {}

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'union.proposed';
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
        $legislature = ChamberActor::legislature($payload, 'F-LEG-029');
        $member = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-029');

        $result = $this->acts->proposeUnion(
            $legislature,
            $member,
            (string) ($payload['kind'] ?? 'join'),
            (array) ($payload['applicant_jurisdiction_ids'] ?? []),
            (array) ($payload['constituent_jurisdiction_ids'] ?? []),
            $payload['union_jurisdiction_id'] ?? null,
        );

        return [
            'legislature_id' => (string) $legislature->id,
            'proposed_by' => (string) $member->id,
            'kind' => (string) ($payload['kind'] ?? 'join'),
        ] + $result;
    }
}
