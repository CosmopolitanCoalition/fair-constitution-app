<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\EmergencyPower;
use App\Models\User;
use App\Services\EmergencyPowerService;

/**
 * F-LEG-025 — Emergency Powers Renewal Vote (R-09; canonical — the
 * Workflows Catalog's "F-LEG-024" citation is recorded drift).
 *
 * The power must be LIVE (active | renewed | under_review | narrowed);
 * the extension carries its own fresh ≤ min(90, resolved max) ceiling;
 * filings land only inside the renewal window (the final
 * cga.emergency_renewal_window_days before expiry — 'as implemented').
 * Opens a FRESH emergency_renew supermajority; the extension applies only
 * on adoption — "nothing rolls over silently", there is no auto-renewal
 * path anywhere (Art. II §7 · CLK-03).
 */
class EmergencyPowersRenewal implements FormHandler
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
        return 'emergency.renewed';
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
        $power = EmergencyPower::query()->find($payload['emergency_power_id'] ?? null);

        if ($power === null) {
            throw new ConstitutionalViolation('Unknown emergency power.', 'Art. II §7 · as implemented');
        }

        $proposer = $this->currentMemberOf($actor, (string) $power->legislature_id);

        $opened = $this->powers->proposeRenewal($power, $proposer, (int) ($payload['extension_days'] ?? 0));

        return $opened + [
            'emergency_power_id' => (string) $power->id,
            'legislature_id'     => (string) $power->legislature_id,
            'jurisdiction_id'    => (string) $power->jurisdiction_id,
            'extension_days'     => (int) ($payload['extension_days'] ?? 0),
        ];
    }
}
