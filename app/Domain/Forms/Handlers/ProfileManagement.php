<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;
use InvalidArgumentException;

/**
 * F-IND-002 — Profile Management (R-01).
 *
 * Records which profile fields changed. Only the whitelisted, non-sensitive
 * fields are ever recorded; the actual user-row update is performed by the
 * calling controller (WI-3/WI-8) around this filing.
 */
class ProfileManagement implements FormHandler
{
    /** Profile fields a filing may touch (and that may appear in the chain). */
    private const ALLOWED_FIELDS = [
        'comm_prefs',
        'display_name',
        'languages',
        'locale',
        'timezone',
    ];

    public function module(): string
    {
        return 'identity';
    }

    public function event(): string
    {
        return 'individual.profile_updated';
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
        $changes = array_intersect_key($payload, array_flip(self::ALLOWED_FIELDS));

        if ($changes === []) {
            throw new InvalidArgumentException(
                'F-IND-002 requires at least one profile field: ' . implode(', ', self::ALLOWED_FIELDS) . '.'
            );
        }

        return [
            'changed_fields' => array_keys($changes),
            'changes'        => $changes,
        ];
    }
}
