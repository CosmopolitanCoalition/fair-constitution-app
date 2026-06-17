<?php

namespace Tests\Feature;

use App\Models\ClusterJoinKey;
use App\Services\Identity\OperatorIdentityService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Phase G (G3c) — the host adoption console over HTTP: an operator logs in on the
 * auth:operator guard and mints an invite key in the browser (no hand-passed
 * handle.secret). Pins the gate (an unauthenticated mint is refused) and the
 * once-only contract (the plaintext rides a flash, not a prop).
 *
 * Live-pg posture, one rolled-back transaction; the test client carries the
 * operator session across requests.
 */
class OperatorHostConsoleTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_op_console';

    public function test_operator_login_gates_the_host_console_and_mints_a_key(): void
    {
        $this->onLivePg(function () {
            // Web-group POSTs run ValidateCsrfToken; disable it for the test client
            // (the gate under test is auth:operator, not CSRF).
            $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

            app(OperatorIdentityService::class)->register('hostop', 'password1234');
            $before = ClusterJoinKey::query()->count();

            // Unauthenticated mint is refused by auth:operator — no key created.
            $this->post('/federation/host/keys', ['max_uses' => 1])->assertRedirect();
            $this->assertSame($before, ClusterJoinKey::query()->count(), 'an unauthenticated mint is refused');

            // Operator logs in (separate guard from the citizen web session).
            $this->post('/operator/login', ['username' => 'hostop', 'password' => 'password1234'])
                ->assertRedirect();

            // The session now carries the operator login — minting succeeds, and the
            // plaintext is flashed ONCE (handle.secret), never a persisted prop.
            $resp = $this->post('/federation/host/keys', ['max_uses' => 1]);
            $resp->assertRedirect()->assertSessionHas('minted_key');
            $this->assertStringContainsString('.', (string) $resp->getSession()->get('minted_key'));
            $this->assertSame($before + 1, ClusterJoinKey::query()->count(), 'the key is minted via the browser endpoint');

            // A wrong operator password is rejected.
            $this->post('/operator/login', ['username' => 'hostop', 'password' => 'nope'])
                ->assertSessionHasErrors('username');
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
