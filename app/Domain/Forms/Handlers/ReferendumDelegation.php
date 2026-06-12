<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\Legislature;
use App\Models\User;
use App\Services\ReferendumService;

/**
 * F-LEG-023 — Referendum Delegation Vote (R-09; canonical per the
 * registry — the Workflows Catalog's "F-LEG-022" citation is recorded
 * catalog drift, never auto-resolved).
 *
 * Validates question/law_text/act_type (setting questions bounds-checked
 * PRE-VOTE through the PROTECTED path) and opens the referendum_delegate
 * SUPERMAJORITY chamber vote. The referendum_questions row is created
 * only on ADOPTION (ChamberActService → ReferendumService::
 * createFromDelegation); the threshold is DERIVED from the act type —
 * no API ever accepts a threshold input (Art. II §6).
 */
class ReferendumDelegation implements FormHandler
{
    use ResolvesLegislativeActor;

    public function __construct(private readonly ReferendumService $referendums)
    {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'referendum.delegated';
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
        $legislature = Legislature::query()->find($payload['legislature_id'] ?? null);

        if ($legislature === null) {
            throw new ConstitutionalViolation('Unknown legislature.', 'Art. II §6 · as implemented');
        }

        $proposer = $this->currentMemberOf($actor, (string) $legislature->id);

        $opened = $this->referendums->proposeDelegation($legislature, $proposer, $payload);

        return $opened + [
            'legislature_id'  => (string) $legislature->id,
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
            'act_type'        => (string) ($payload['act_type'] ?? ''),
            'threshold'       => ReferendumService::deriveThreshold((string) ($payload['act_type'] ?? '')),
        ];
    }
}
