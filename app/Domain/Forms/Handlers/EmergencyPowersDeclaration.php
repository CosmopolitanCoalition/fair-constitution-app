<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\Legislature;
use App\Models\User;
use App\Services\EmergencyPowerService;

/**
 * F-LEG-024 — Emergency Powers Declaration Vote (R-09; canonical — the
 * Workflows Catalog's "F-LEG-023" citation is recorded drift).
 *
 * ALL validation runs PRE-VOTE in EmergencyPowerService (Art. II §7):
 * closed cause enum (natural_disaster | actual_invasion — economic /
 * political / public-order rationales rejected with citation), duration
 * 1..min(90, resolved emergency_powers_max_days), area ≤ this
 * legislature's authority, methods non-empty. Rejections are recorded as
 * rejected=true chain rows — the operator-visible record, exactly like
 * F-LEG-031.
 *
 * Opens the emergency_invoke SUPERMAJORITY vote; the power row + CLK-03
 * countdown exist only on adoption.
 */
class EmergencyPowersDeclaration implements FormHandler
{
    use ResolvesLegislativeActor;

    public function __construct(private readonly EmergencyPowerService $powers)
    {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'emergency.invoked';
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
            throw new ConstitutionalViolation('Unknown legislature.', 'Art. II §7 · as implemented');
        }

        $proposer = $this->currentMemberOf($actor, (string) $legislature->id);

        $opened = $this->powers->proposeInvocation($legislature, $proposer, $payload);

        return $opened + [
            'legislature_id'  => (string) $legislature->id,
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
            'cause'           => (string) ($payload['cause'] ?? ''),
            'duration_days'   => (int) ($payload['duration_days'] ?? 0),
        ];
    }
}
