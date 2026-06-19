<?php

namespace Tests\Feature\Matrix;

use Tests\TestCase;

/**
 * Phase K-3 (K3-D) — the inbound AS-API is gated by the hs_token (VerifyMatrixAppService), the AS-API
 * analogue of the Ed25519 peer-signature gate on /api/federation. A forged push can never inject
 * events as the appservice; transactions are idempotent on txnId; the appservice owns only the @u-*
 * (+ sender) namespace. This is HTTP-only (no DB) — it pins the auth boundary, not event handling.
 */
class AppServiceAuthTest extends TestCase
{
    private function hsToken(): string
    {
        return (string) config('matrix.appservice.hs_token');
    }

    public function test_a_transaction_without_the_hs_token_is_forbidden(): void
    {
        $this->putJson('/_matrix/app/v1/transactions/txn-no-token', ['events' => []])
            ->assertStatus(403)
            ->assertJson(['errcode' => 'M_FORBIDDEN']);
    }

    public function test_a_transaction_with_a_wrong_hs_token_is_forbidden(): void
    {
        $this->withToken('not-the-real-hs-token')
            ->putJson('/_matrix/app/v1/transactions/txn-wrong', ['events' => []])
            ->assertStatus(403);
    }

    public function test_a_transaction_with_the_hs_token_is_accepted_and_idempotent(): void
    {
        $this->withToken($this->hsToken())
            ->putJson('/_matrix/app/v1/transactions/txn-ok', ['events' => []])
            ->assertOk();

        // A replay of the same txnId is acked again (processed once — Synapse retries safely).
        $this->withToken($this->hsToken())
            ->putJson('/_matrix/app/v1/transactions/txn-ok', ['events' => []])
            ->assertOk();
    }

    public function test_the_appservice_owns_only_its_user_namespace(): void
    {
        $this->withToken($this->hsToken())
            ->getJson('/_matrix/app/v1/users/@u-someresident:localhost')
            ->assertOk();

        $this->withToken($this->hsToken())
            ->getJson('/_matrix/app/v1/users/@stranger:localhost')
            ->assertStatus(404);
    }
}
