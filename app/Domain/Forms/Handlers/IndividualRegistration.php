<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;
use InvalidArgumentException;

/**
 * F-IND-001 — Individual Registration (filed by R-01; guest-filable at
 * registration time, so no role gate).
 *
 * Field presence/format validation (name, email, password) is handled
 * upstream by the WI-3 registration controller's FormRequest; this handler
 * records the registration snapshot to the audit chain. The user row
 * itself is created by the WI-3 controller around this filing.
 *
 * The snapshot NEVER includes credential material.
 */
class IndividualRegistration implements FormHandler
{
    public function module(): string
    {
        return 'identity';
    }

    public function event(): string
    {
        return 'individual.registered';
    }

    public function requiredRoles(): array
    {
        return []; // guest-filable — registration is how R-01 comes to exist
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $name  = $payload['name'] ?? null;
        $email = $payload['email'] ?? null;

        if (! is_string($name) || trim($name) === '' || ! is_string($email) || trim($email) === '') {
            throw new InvalidArgumentException('F-IND-001 requires name and email (validated upstream).');
        }

        return [
            'name'           => $name,
            'email'          => $email,
            'languages'      => $payload['languages'] ?? ['en'],
            'timezone'       => $payload['timezone'] ?? 'UTC',
            'terms_accepted' => (bool) ($payload['terms'] ?? false),
            'user_id'        => $payload['user_id'] ?? null,
        ];
    }
}
