<?php

namespace Tests\Feature;

use App\Models\OperatorAccount;
use App\Models\User;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\OperatorIdentityService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Phase G (G-OP) — operator-account security + lifecycle invariants. The operator
 * password is a LOCAL credential that must never serialize (it never federates),
 * logout clears only the operator plane (a citizen session survives the two-guard
 * design), and a suspended account fails closed at authentication.
 *
 * Live-pg posture.
 */
class OperatorAccountSecurityTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_operator_security';

    public function test_operator_password_never_serializes(): void
    {
        $this->onLivePg(function () {
            $op = $this->operator('correct horse battery');

            $this->assertArrayNotHasKey('password', $op->toArray(), 'password is hidden from toArray');

            $hash = (string) $op->getRawOriginal('password');
            $this->assertNotSame('', $hash);
            $this->assertStringNotContainsString($hash, json_encode($op), 'the password hash never appears in JSON');
            $this->assertStringNotContainsString('password', json_encode($op), 'no password key in JSON');
        });
    }

    public function test_logout_clears_only_the_operator_guard(): void
    {
        $this->onLivePg(function () {
            $op = $this->operator('correct horse battery');
            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();

            $this->be($citizen, 'web')->be($op, 'operator')
                ->post('/operator/logout')
                ->assertRedirect();

            $this->assertGuest('operator');
            $this->assertAuthenticated('web');
        });
    }

    public function test_a_suspended_operator_cannot_authenticate(): void
    {
        $this->onLivePg(function () {
            $username = 'suspendop_'.Str::lower(Str::random(8));
            $op = $this->operator('correct horse battery', $username);

            $op->status = OperatorAccount::STATUS_SUSPENDED;
            $op->save();

            $this->assertNull(
                app(OperatorIdentityService::class)->authenticate($username, 'correct horse battery'),
                'a suspended operator fails closed at authentication',
            );
        });
    }

    private function operator(string $password, ?string $username = null): OperatorAccount
    {
        app(InstanceIdentityService::class)->ensureIdentity();

        return app(OperatorIdentityService::class)->register($username ?? 'secop_'.Str::lower(Str::random(8)), $password);
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();
        $this->withoutMiddleware(ValidateCsrfToken::class);

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
