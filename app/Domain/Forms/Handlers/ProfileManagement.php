<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;
use InvalidArgumentException;

/**
 * F-IND-002 — Profile Management (R-01).
 *
 * Applies the whitelisted profile fields to the actor's user row INSIDE
 * the engine transaction (WI-8) — mutation and audit entry commit or roll
 * back together (WF-SYS-04). Only non-sensitive fields are accepted, and
 * only those fields ever appear on the chain.
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

        // Apply inside the engine transaction — no mutation without its
        // chain entry, no entry without its mutation. F-IND-002 is filed
        // by the individual, so $actor is always present here (the engine
        // role-gates R-01 before handle()).
        if ($actor !== null) {
            $actor->fill($changes)->save();
        }

        return [
            'changed_fields' => array_keys($changes),
            'changes'        => $changes,
        ];
    }
}
