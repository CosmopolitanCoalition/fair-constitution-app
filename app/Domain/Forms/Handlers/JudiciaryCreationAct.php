<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\User;
use App\Services\Judiciary\JudiciaryActService;

/**
 * F-LEG-017 — Judiciary Creation Act (WF-JUD-01).
 *
 * The DEFAULT and ONLY output is an APPOINTED court (Art. IV §1 hard
 * constraint — judiciary_is_elected=false; elected courts come ONLY via
 * F-LEG-018 conversion). A supermajority chamber vote (judiciary_create) on
 * {court_name, function_text, judges_per_constituent?, committee_judge_count?};
 * on adoption the charter law enacts, the nomination mode is DERIVED from
 * the jurisdiction's constituent structure (Art. IV §2), and the vacant
 * seat pool allocates. The court stays `creating` until every seat is
 * consented (F-LEG-021), then advances to `appointed`.
 */
class JudiciaryCreationAct implements FormHandler
{
    public function __construct(
        private readonly JudiciaryActService $acts,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'judiciary.creation_filed';
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
        $legislature = ChamberActor::legislature($payload, 'F-LEG-017');
        $member = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-017');

        $result = $this->acts->proposeCreation(
            $legislature,
            $member,
            (string) ($payload['court_name'] ?? ''),
            (string) ($payload['function_text'] ?? ''),
            isset($payload['judges_per_constituent']) ? (int) $payload['judges_per_constituent'] : null,
            isset($payload['committee_judge_count']) ? (int) $payload['committee_judge_count'] : null,
        );

        return [
            'action' => 'propose',
            'legislature_id' => (string) $legislature->id,
            'proposed_by' => (string) $member->id,
        ] + $result;
    }
}
