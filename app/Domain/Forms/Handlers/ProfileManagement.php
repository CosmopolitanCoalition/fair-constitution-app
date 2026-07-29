<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Models\SocialProfile;
use App\Models\User;
use InvalidArgumentException;

/**
 * F-IND-002 — Profile Management (R-01).
 *
 * Applies the whitelisted profile fields to the actor's user row INSIDE
 * the engine transaction (WI-8) — mutation and audit entry commit or roll
 * back together (WF-SYS-04). Only non-sensitive fields are accepted, and
 * only those fields ever appear on the chain.
 *
 * THE SOCIAL-PROFILE EXTENSION (Wave 4 §②). F-IND-002 also manages the
 * player's PUBLIC social profile — the pseudonymous handle, the bio, and
 * the visibility preference (social_profiles). This is the self-edit door
 * the public ?who= page needs; before it, only demo seeders ever wrote a
 * social_profiles row.
 *
 * THE PSEUDONYMITY RAIL ON THE CHAIN (Art. I). The USER fields carry their
 * values onto the public chain as before (display_name is the chosen
 * pseudonym — already public). The SOCIAL fields do NOT: the chain records
 * only THAT the profile changed, never the handle, bio, or visibility
 * value. A chained handle *history* would let anyone correlate an old
 * pseudonym to a new one (a de-anonymisation vector); a bio can be private
 * (visibility=private); and the visibility flag is itself a privacy choice
 * that "NEVER gates a right" and is nobody else's record. So social values
 * are applied to the row and withheld from the audit payload.
 */
class ProfileManagement implements FormHandler
{
    /** User-row fields a filing may touch (and that may appear in the chain). */
    private const ALLOWED_FIELDS = [
        'comm_prefs',
        'display_name',
        'languages',
        'locale',
        'timezone',
    ];

    /** Social-profile fields a filing may touch (NAMES chained, values withheld). */
    private const SOCIAL_FIELDS = [
        'handle',
        'bio',
        'visibility',
    ];

    private const BIO_MAX = 2000;

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
        $userChanges = array_intersect_key($payload, array_flip(self::ALLOWED_FIELDS));
        $socialChanges = array_intersect_key($payload, array_flip(self::SOCIAL_FIELDS));

        if ($userChanges === [] && $socialChanges === []) {
            throw new InvalidArgumentException(
                'F-IND-002 requires at least one profile field: '
                . implode(', ', [...self::ALLOWED_FIELDS, ...self::SOCIAL_FIELDS]) . '.'
            );
        }

        // Validate the social fields BEFORE touching anything, so a bad
        // handle never leaves a half-applied profile (the engine's
        // transaction would roll back anyway — this keeps the failure clean).
        $social = $actor !== null ? $this->validateSocial($actor, $socialChanges) : [];

        // Apply inside the engine transaction — no mutation without its
        // chain entry, no entry without its mutation. F-IND-002 is filed
        // by the individual, so $actor is always present here (the engine
        // role-gates R-01 before handle()).
        if ($actor !== null) {
            if ($userChanges !== []) {
                $actor->fill($userChanges)->save();
            }

            if ($social !== []) {
                $profile = SocialProfile::query()->firstOrNew(['user_id' => (string) $actor->getKey()]);
                $profile->fill($social)->save();
            }
        }

        // The chain payload: user field names+values, social field NAMES ONLY.
        return [
            'changed_fields' => array_values(array_unique([
                ...array_keys($userChanges),
                ...array_keys($social),
            ])),
            'changes' => $userChanges,
        ];
    }

    /**
     * Normalise + validate the social-profile fields. Returns the fields to
     * apply (never echoes them to the chain — see the class docblock).
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, string>
     */
    private function validateSocial(User $actor, array $changes): array
    {
        $out = [];

        if (array_key_exists('handle', $changes)) {
            $handle = mb_strtolower(trim((string) $changes['handle']));

            if (! preg_match('/^[a-z0-9][a-z0-9_-]{2,63}$/', $handle)) {
                throw new InvalidArgumentException(
                    'A handle is 3–64 characters: lowercase letters, digits, hyphen or underscore, starting with a letter or digit.'
                );
            }

            $taken = SocialProfile::query()
                ->whereRaw('lower(handle) = ?', [$handle])
                ->where('user_id', '!=', (string) $actor->getKey())
                ->whereNull('deleted_at')
                ->exists();

            if ($taken) {
                throw new InvalidArgumentException('That handle is already taken — pick another.');
            }

            $out['handle'] = $handle;
        }

        if (array_key_exists('bio', $changes)) {
            $bio = trim((string) $changes['bio']);

            if (mb_strlen($bio) > self::BIO_MAX) {
                throw new InvalidArgumentException('A bio is at most ' . self::BIO_MAX . ' characters.');
            }

            $out['bio'] = $bio;
        }

        if (array_key_exists('visibility', $changes)) {
            $visibility = (string) $changes['visibility'];
            $allowed = [
                SocialProfile::VISIBILITY_PUBLIC,
                SocialProfile::VISIBILITY_JURISDICTION,
                SocialProfile::VISIBILITY_PRIVATE,
            ];

            if (! in_array($visibility, $allowed, true)) {
                throw new InvalidArgumentException(
                    'Visibility is one of: ' . implode(', ', $allowed) . '.'
                );
            }

            $out['visibility'] = $visibility;
        }

        return $out;
    }
}
