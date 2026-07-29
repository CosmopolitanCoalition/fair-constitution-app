<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * UI<->CLI parity doors (ruling 10) — the WEB doors must carry the SAME guard
 * their CLI twins enforce (DevBoardSeatParityTest mold). These pin the guard on
 * each new web door: a caller who could not run the command must not reach the
 * capability through the browser either.
 *
 *   audit:reconcile        → POST /system/audit-chain/reconcile  (operator only)
 *   institutions:provision → POST /building/provision            (operator only)
 *   jurisdiction:activate  → POST /dev/jurisdictions/{id}/activate (DevToolsEnabled)
 *
 * Runs on the guarded live-pg connection (users are created).
 */
class ParityDoorGuardsTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_parity_doors';

    private const CSRF = 'parity-door-csrf';

    public function test_audit_reconcile_refuses_a_non_operator(): void
    {
        $this->onLivePg(function () {
            $this->actingAs($this->user(isOperator: false))
                ->withSession(['_token' => self::CSRF])
                ->post('/system/audit-chain/reconcile', ['_token' => self::CSRF, 'reason' => 'x'])
                ->assertForbidden(); // abort_unless(is_operator) — the same gate audit:reconcile's resolveSigner enforces
        });
    }

    public function test_audit_reconcile_lets_an_operator_past_the_gate_but_requires_a_reason(): void
    {
        $this->onLivePg(function () {
            // is_operator passes abort_unless; the empty reason then trips the
            // service's required-reason rule BEFORE any chain walk.
            $this->actingAs($this->user(isOperator: true))
                ->withSession(['_token' => self::CSRF])
                ->post('/system/audit-chain/reconcile', ['_token' => self::CSRF, 'reason' => ''])
                ->assertRedirect()
                ->assertSessionHasErrors('reason');
        });
    }

    public function test_institutions_provision_refuses_a_non_operator(): void
    {
        $this->onLivePg(function () {
            // /building is a PUBLIC route, so the provision POST must carry its
            // OWN operator gate rather than inherit one from the page.
            $this->actingAs($this->user(isOperator: false))
                ->withSession(['_token' => self::CSRF])
                ->post('/building/provision', ['_token' => self::CSRF, 'dry_run' => true])
                ->assertForbidden();
        });
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function user(bool $isOperator): User
    {
        return User::create([
            'name' => 'Parity '.Str::random(5),
            'email' => 'parity-door-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
            'is_operator' => $isOperator,
        ]);
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        app(RoleService::class)->flush();
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            app(RoleService::class)->flush();
        }
    }
}
