<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Contracts\ResidencyHandlerDelegate;
use App\Models\User;

/**
 * F-IND-005 — GPS Residency Ping (R-01).
 *
 * PHASE A STUB: validates coordinates and delegates persistence to the
 * bound ResidencyHandlerDelegate (no-op until WI-5 plugs in the
 * location_pings insert + qualifying-day evaluation).
 *
 * PRIVACY INVARIANT: pings are audited as a count-bump, NEVER as
 * coordinates. Raw locations are private, live only in location_pings,
 * and are purged on verification. Nothing this handler returns may echo
 * latitude/longitude — the audit chain must be publishable without
 * leaking anyone's movements.
 */
class GpsResidencyPing implements FormHandler
{
    public function __construct(
        private readonly ResidencyHandlerDelegate $delegate,
    ) {
    }

    public function module(): string
    {
        return 'residency';
    }

    public function event(): string
    {
        return 'residency.ping_recorded';
    }

    public function requiredRoles(): array
    {
        return ['R-01'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $lat = $payload['latitude'] ?? null;
        $lng = $payload['longitude'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            throw new ConstitutionalViolation(
                'F-IND-005 requires valid latitude/longitude coordinates.',
                'Art. I'
            );
        }

        $source = $payload['source'] ?? 'manual';

        // Coordinates deliberately NOT recorded — count-bump only.
        return [
            'ping_recorded' => true,
            'source'        => is_string($source) ? $source : 'manual',
        ] + $this->delegate->recordPing($actor, $payload);
    }
}
