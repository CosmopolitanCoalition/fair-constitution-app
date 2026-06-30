<?php

namespace App\Services\Invites;

use App\Models\Invite;
use App\Models\Jurisdiction;
use App\Models\SocialSpace;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Social\PrivateRoomService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Person-to-person invites — the growth primitive. Mints a shareable `handle.secret`
 * for a destination the inviter can already reach, resolves it constant-time, and
 * redeems it atomically (recording attribution). Crypto posture mirrors
 * MirrorJoinKeyService; the destination is always a SERVER-BUILT same-origin app path
 * (the open-redirect / SSRF guard), and redeeming an invite confers NO power — only a
 * landing spot the destination already grants under Art. I / Art. II §2.
 */
class InviteService
{
    public const DEFAULT_TTL_DAYS = 14;

    /** Public, read-only proceeding route prefixes an invite may point at (Art. II §2). */
    private const PROCEEDING_PREFIXES = [
        'legislatures', 'bills', 'executives', 'departments',
        'judiciaries', 'cases', 'civic/petitions', 'system/public-records',
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Mint a shareable invite for a destination the inviter can already reach. Returns the
     * plaintext `handle.secret` (shown ONCE) and the row. The same-origin destination path is
     * built SERVER-SIDE from a whitelisted kind — the caller never supplies a raw URL.
     *
     * @param  array{kind:string,jurisdiction_id?:string,space?:string,path?:string,label?:string,max_uses?:int|null,ttl_days?:int|null}  $spec
     * @return array{0:string,1:Invite}
     */
    public function mint(User $inviter, array $spec): array
    {
        $kind = (string) ($spec['kind'] ?? '');
        [$path, $label] = $this->resolveDestination($kind, $spec);

        $handle = Str::lower(Str::random(12));
        $secret = bin2hex(random_bytes(32));

        $ttlDays = array_key_exists('ttl_days', $spec) ? $spec['ttl_days'] : self::DEFAULT_TTL_DAYS;
        $expiresAt = $ttlDays === null ? null : Carbon::now()->addDays((int) $ttlDays);

        $maxUses = array_key_exists('max_uses', $spec) ? $spec['max_uses'] : null;
        if ($maxUses !== null) {
            $maxUses = max(1, (int) $maxUses);
        }

        $invite = Invite::create([
            'handle'          => $handle,
            'token_hash'      => password_hash($secret, PASSWORD_ARGON2ID),
            'inviter_user_id' => $inviter->getKey(),
            'kind'            => $kind,
            'destination'     => array_filter([
                'path'            => $path,
                'jurisdiction_id' => $spec['jurisdiction_id'] ?? null,
                'space'           => $spec['space'] ?? null,
                'space_id'        => $spec['space_id'] ?? null,
            ], static fn ($v) => $v !== null),
            'label'           => $spec['label'] ?? $label,
            'max_uses'        => $maxUses,
            'uses'            => 0,
            'expires_at'      => $expiresAt,
        ]);

        // Public handle + kind only — the secret is NEVER logged.
        $this->audit->append('invite', 'invite.minted', [
            'handle' => $handle,
            'kind'   => $kind,
        ], null, (string) $inviter->getKey());

        return [$handle.'.'.$secret, $invite];
    }

    /** Resolve a presented plaintext to a LIVE invite (constant-time secret check), or null. */
    public function resolve(string $plaintext): ?Invite
    {
        [$handle, $secret] = array_pad(explode('.', $plaintext, 2), 2, '');

        if ($handle === '' || $secret === '') {
            return null;
        }

        $invite = Invite::query()->where('handle', $handle)->first();

        if ($invite === null || ! $invite->isLive() || ! password_verify($secret, (string) $invite->token_hash)) {
            return null;
        }

        return $invite;
    }

    /**
     * Atomically redeem one use under a row lock, recording attribution on the redeeming user
     * (set ONCE — a user keeps their first inviter, never self-attributes). Returns false if the
     * invite was exhausted/revoked/expired by the time the lock was taken; the caller proceeds
     * regardless (fail-open — an invite never blocks a signup). Reusable invites (max_uses NULL)
     * succeed while live.
     */
    public function consume(Invite $invite, User $user): bool
    {
        return DB::transaction(function () use ($invite, $user): bool {
            $locked = Invite::query()->whereKey($invite->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isLive()) {
                return false;
            }

            $locked->uses = (int) $locked->uses + 1;
            $locked->save();

            if ($user->invited_by_user_id === null
                && $locked->inviter_user_id !== null
                && (string) $locked->inviter_user_id !== (string) $user->getKey()
            ) {
                $user->forceFill(['invited_by_user_id' => $locked->inviter_user_id])->save();
            }

            $this->audit->append('invite', 'invite.redeemed', [
                'handle' => $locked->handle,
                'kind'   => $locked->kind,
            ], null, (string) $user->getKey());

            return true;
        });
    }

    /** Revoke an invite by its public handle (idempotent on an already-revoked one). */
    public function revoke(string $handle): bool
    {
        $invite = Invite::query()->where('handle', $handle)->whereNull('revoked_at')->first();

        if ($invite === null) {
            return false;
        }

        $invite->revoked_at = now();
        $invite->save();

        $this->audit->append('invite', 'invite.revoked', ['handle' => $handle]);

        return true;
    }

    /**
     * Apply any kind-specific side effect of redeeming an invite. Currently a `space` invite admits the
     * redeemer to the private room as a member, so a friend who clicks the link lands inside the room.
     * Idempotent and FAIL-SOFT — it never blocks the redeem/signup (a missing room just means no membership).
     */
    public function grantAccess(Invite $invite, User $user): void
    {
        if ($invite->kind !== Invite::KIND_SPACE) {
            return;
        }

        $spaceId = (string) ($invite->destination['space_id'] ?? '');
        $space = SocialSpace::query()->whereKey($spaceId)->where('is_private', true)->first();

        if ($space !== null) {
            app(PrivateRoomService::class)->admit($space, $user);
        }
    }

    /**
     * Build the same-origin destination path + a default label for a whitelisted kind. Throws on
     * an unknown kind or a path that isn't a public proceeding route — the open-redirect / SSRF
     * guard: a link can only ever point INSIDE the app.
     *
     * @return array{0:string,1:?string}
     */
    private function resolveDestination(string $kind, array $spec): array
    {
        return match ($kind) {
            Invite::KIND_CALL, Invite::KIND_COMMONS => $this->commonsDestination($spec),
            Invite::KIND_PROCEEDING => $this->proceedingDestination($spec),
            Invite::KIND_SPACE => $this->spaceDestination($spec),
            default => throw new InvalidArgumentException("Unsupported invite kind [{$kind}]."),
        };
    }

    /**
     * A private-room invite — points at the inviter's own private room. The redeemer is admitted as a
     * member on redeem (grantAccess). Same-origin path, validated against a real private space.
     *
     * @return array{0:string,1:?string}
     */
    private function spaceDestination(array $spec): array
    {
        $spaceId = (string) ($spec['space_id'] ?? '');

        $space = SocialSpace::query()->whereKey($spaceId)->where('is_private', true)->first();
        if ($space === null) {
            throw new InvalidArgumentException('Unknown private room for a room invite.');
        }

        return ['/civic/rooms/'.rawurlencode($spaceId), $space->title];
    }

    /** @return array{0:string,1:?string} */
    private function commonsDestination(array $spec): array
    {
        $jurisdictionId = (string) ($spec['jurisdiction_id'] ?? '');
        $space = ($spec['space'] ?? 'square') === 'halls' ? 'halls' : 'square';

        $jurisdiction = Jurisdiction::query()->find($jurisdictionId);
        if ($jurisdiction === null) {
            throw new InvalidArgumentException('Unknown jurisdiction for a commons invite.');
        }

        $path = "/civic/commons/{$space}?jurisdiction=".rawurlencode($jurisdictionId);
        $label = trim(($jurisdiction->name ?? 'Jurisdiction').' — '.($space === 'halls' ? 'Halls' : 'Public Square'));

        return [$path, $label];
    }

    /** @return array{0:string,1:?string} */
    private function proceedingDestination(array $spec): array
    {
        $raw = '/'.ltrim((string) ($spec['path'] ?? ''), '/');

        // Reject anything that escapes the origin: a scheme, a host (incl. //host), a backslash trick,
        // or a `..` segment that would resolve back out of the allowed prefix on redirect.
        $parsed = parse_url($raw);
        if ($parsed === false || isset($parsed['scheme']) || isset($parsed['host'])
            || ! isset($parsed['path']) || str_contains($raw, '\\')
            || str_contains((string) $parsed['path'], '..')
        ) {
            throw new InvalidArgumentException('A proceeding invite must be a same-origin app path.');
        }

        $head = ltrim($parsed['path'], '/');
        $allowed = false;
        foreach (self::PROCEEDING_PREFIXES as $prefix) {
            if ($head === $prefix || str_starts_with($head, $prefix.'/')) {
                $allowed = true;
                break;
            }
        }
        if (! $allowed) {
            throw new InvalidArgumentException('That path is not a public proceeding.');
        }

        return [$raw, $spec['label'] ?? null];
    }
}
