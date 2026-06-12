<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;
use InvalidArgumentException;

/**
 * F-IND-001 — Individual Registration (filed by R-01; guest-filable at
 * registration time, so no role gate).
 *
 * Field presence/format validation (name, email, password rules) is
 * handled upstream by the registration controller; this handler performs
 * the mutation — it creates the Individual's user row inside the engine
 * transaction — and returns the audit snapshot.
 *
 * Credential handling: the controller hashes the password BEFORE filing
 * and passes it as `password_hash`; raw `password` / `password_confirmation`
 * never enter the engine payload. The returned snapshot carries no
 * credential material, and the engine additionally strips `password*`
 * keys from rejection payloads (SENSITIVE_KEYS).
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
        $hash  = $payload['password_hash'] ?? null;

        if (! is_string($name) || trim($name) === '' || ! is_string($email) || trim($email) === '') {
            throw new InvalidArgumentException('F-IND-001 requires name and email (validated upstream).');
        }

        if (! is_string($hash) || $hash === '') {
            throw new InvalidArgumentException('F-IND-001 requires a pre-hashed password (password_hash).');
        }

        if (! ($payload['terms'] ?? false)) {
            throw new InvalidArgumentException('F-IND-001 requires terms acceptance (validated upstream).');
        }

        $languages = array_values(array_filter(
            (array) ($payload['languages'] ?? ['en']),
            fn ($lang) => is_string($lang) && $lang !== ''
        )) ?: ['en'];

        $user = User::create([
            'name'              => $name,
            'display_name'      => $payload['display_name'] ?? null,
            'email'             => $email,
            'password'          => $hash, // hashed cast passes through already-hashed values
            'status'            => 'registered',
            'languages'         => $languages,
            'timezone'          => is_string($payload['timezone'] ?? null) && $payload['timezone'] !== ''
                ? $payload['timezone']
                : 'UTC',
            'locale'            => $languages[0],
            'terms_accepted_at' => now(),
        ]);

        // Audit snapshot — NEVER credential material.
        return [
            'user_id'        => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'languages'      => $user->languages,
            'timezone'       => $user->timezone,
            'terms_accepted' => true,
        ];
    }
}
