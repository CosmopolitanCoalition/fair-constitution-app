<?php

namespace App\Services\Mirror;

use App\Models\ClusterJoinKey;
use App\Services\AuditService;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cluster join keys (Phase G, G2 — host side). A host mints a secret; a would-be
 * mirror presents it at adoption. Security posture:
 *   - 32-byte CSPRNG secret, stored ONLY as an Argon2id hash (`password_hash`);
 *   - the plaintext `handle.secret` is returned ONCE and never persisted/logged;
 *   - only the public `handle` is ever audited;
 *   - verification is constant-time (`password_verify`);
 *   - consumption is atomic (SELECT … FOR UPDATE) so a one-use key admits exactly
 *     one mirror even under a concurrent `/adopt` race.
 */
class MirrorJoinKeyService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Mint a join key. Returns the plaintext (shown to the operator ONCE) and the
     * row. The plaintext is `handle.secret`; only the Argon2id hash is stored.
     *
     * @return array{0:string,1:ClusterJoinKey}
     */
    public function mint(int $maxUses = 1, ?DateTimeInterface $expiresAt = null, ?string $scopeJurisdictionId = null): array
    {
        $handle = Str::lower(Str::random(12));
        $secret = bin2hex(random_bytes(32));

        $key = ClusterJoinKey::create([
            'handle' => $handle,
            'key_hash' => password_hash($secret, PASSWORD_ARGON2ID),
            'max_uses' => max(1, $maxUses),
            'uses' => 0,
            'scope_jurisdiction_id' => $scopeJurisdictionId,
            'expires_at' => $expiresAt,
        ]);

        $this->audit->append('mirror', 'mirror.join_key_minted',
            ['handle' => $handle, 'max_uses' => $key->max_uses], 'WF-JUR-06');

        return [$handle.'.'.$secret, $key];
    }

    /**
     * Verify a presented plaintext against a LIVE key (constant-time secret check).
     * Returns the key or null — never reveals which check failed.
     */
    public function verify(string $plaintext): ?ClusterJoinKey
    {
        [$handle, $secret] = array_pad(explode('.', $plaintext, 2), 2, '');

        if ($handle === '' || $secret === '') {
            return null;
        }

        $key = ClusterJoinKey::query()->where('handle', $handle)->first();

        if ($key === null || ! $key->isLive() || ! password_verify($secret, (string) $key->key_hash)) {
            return null;
        }

        return $key;
    }

    /**
     * Atomically consume one use under a row lock. Returns false if the key was
     * exhausted/revoked/expired by the time the lock was taken (the concurrent-race
     * loser). The caller admits the mirror only on true.
     */
    public function consume(ClusterJoinKey $key): bool
    {
        return DB::transaction(function () use ($key): bool {
            $locked = ClusterJoinKey::query()->whereKey($key->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isLive()) {
                return false;
            }

            $locked->uses = (int) $locked->uses + 1;
            $locked->save();

            return true;
        });
    }

    /** Revoke a key by its public handle (idempotent on an already-revoked key). */
    public function revoke(string $handle): bool
    {
        $key = ClusterJoinKey::query()->where('handle', $handle)->whereNull('revoked_at')->first();

        if ($key === null) {
            return false;
        }

        $key->revoked_at = now();
        $key->save();

        $this->audit->append('mirror', 'mirror.join_key_revoked', ['handle' => $handle], 'WF-JUR-06');

        return true;
    }
}
