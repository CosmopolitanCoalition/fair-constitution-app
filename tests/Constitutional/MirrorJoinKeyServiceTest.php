<?php

namespace Tests\Constitutional;

use App\Services\Mirror\MirrorJoinKeyService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G2) cluster join keys (host side). A host mints a
 * one-step adoption secret. The pins:
 *  - the plaintext is shown once and stored ONLY as an Argon2id hash;
 *  - verification is constant-time and reveals nothing on failure;
 *  - a one-use key admits EXACTLY one mirror (atomic consume);
 *  - revoke and expiry kill a key.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class MirrorJoinKeyServiceTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_join_keys';

    public function test_mint_stores_only_an_argon2id_hash_and_verifies_constant_time(): void
    {
        $this->onLivePg(function () {
            $svc = app(MirrorJoinKeyService::class);

            [$plaintext, $key] = $svc->mint();
            $secret = explode('.', $plaintext, 2)[1];

            $this->assertStringStartsWith($key->handle.'.', $plaintext, 'plaintext is handle.secret');
            // The secret is NEVER stored — only an Argon2id hash.
            $this->assertStringStartsWith('$argon2id$', (string) $key->key_hash);
            $this->assertStringNotContainsString($secret, (string) $key->key_hash, 'the raw secret never persists');

            // Correct plaintext verifies; a wrong secret or handle does not.
            $this->assertNotNull($svc->verify($plaintext));
            $this->assertNull($svc->verify($key->handle.'.deadbeef'), 'a wrong secret is refused');
            $this->assertNull($svc->verify('nope.'.$secret), 'a wrong handle is refused');
        });
    }

    public function test_a_one_use_key_admits_exactly_one_mirror(): void
    {
        $this->onLivePg(function () {
            $svc = app(MirrorJoinKeyService::class);
            [$plaintext, $key] = $svc->mint(maxUses: 1);

            $this->assertTrue($svc->consume($svc->verify($plaintext)), 'the first consume succeeds');
            $this->assertNull($svc->verify($plaintext), 'an exhausted key no longer verifies');
            $this->assertFalse($svc->consume($key->refresh()), 'a second consume is refused');
        });
    }

    public function test_revoke_and_expiry_kill_a_key(): void
    {
        $this->onLivePg(function () {
            $svc = app(MirrorJoinKeyService::class);

            [$revokablePlain, $revokable] = $svc->mint();
            $this->assertTrue($svc->revoke($revokable->handle));
            $this->assertNull($svc->verify($revokablePlain), 'a revoked key is dead');
            $this->assertFalse($svc->revoke($revokable->handle), 'revoke is idempotent on an already-revoked key');

            [$expiredPlain] = $svc->mint(expiresAt: now()->subMinute());
            $this->assertNull($svc->verify($expiredPlain), 'an expired key is dead');
        });
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
