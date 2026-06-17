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
 * Phase G (G-OP) — instance GENESIS: SetupController::createFounder. The fresh
 * cross-machine run STARTS here, and it was untested. Pins: founding provisions
 * BOTH planes in one transaction — the citizen User (is_operator) AND a separate
 * OperatorAccount (the operator plane; key-possession + local password, no FK to
 * users) — and the idempotency guard refuses a second founder (409) without
 * minting a stray operator account.
 *
 * Live-pg posture; the happy path soft-deletes existing users so the genesis
 * guard passes (rolled back). A random founder email avoids the unique collision.
 */
class FounderBootstrapTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_founder';

    public function test_founder_creation_provisions_citizen_and_operator_planes(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        try {
            app(InstanceIdentityService::class)->ensureIdentity();

            // Empty both planes so the genesis guard passes (soft-delete, rolled back).
            User::query()->delete();
            OperatorAccount::query()->delete();

            $email = 'founder_'.Str::lower(Str::random(8)).'@example.test';
            $secret = 'correct horse battery staple';

            $resp = $this->postJson('/api/setup/bootstrap/create-founder', [
                'name'                  => 'The Founder',
                'email'                 => $email,
                'password'              => $secret,
                'password_confirmation' => $secret,
            ]);
            $resp->assertStatus(200);
            $this->assertAuthenticated(); // the founder is logged in on the web guard

            // Citizen plane.
            $user = User::query()->where('email', $email)->first();
            $this->assertNotNull($user, 'the founder citizen row is created');
            $this->assertTrue((bool) $user->is_operator, 'the founder is flagged is_operator');

            // Operator plane — a SEPARATE row (own guard, no FK to users), username = email.
            $op = OperatorAccount::query()->where('username', $email)->first();
            $this->assertNotNull($op, 'the founder also gets a first operator account');
            $this->assertNotSame((string) $user->id, (string) $op->id, 'the two planes are distinct rows');

            // The operator can authenticate LOCALLY by password (mesh recognition is key-possession).
            $this->assertNotNull(
                app(OperatorIdentityService::class)->authenticate($email, $secret),
                'the founder operator authenticates with the founding password',
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }

    public function test_a_second_founder_is_refused_without_minting_an_operator(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        try {
            app(InstanceIdentityService::class)->ensureIdentity();

            // A founder already exists on a set-up instance (the live-pg posture);
            // the genesis guard must refuse a second one.
            $this->assertTrue(User::query()->exists(), 'precondition: the instance is already founded');
            $before = OperatorAccount::query()->count();

            $resp = $this->postJson('/api/setup/bootstrap/create-founder', [
                'name'                  => 'Usurper',
                'email'                 => 'usurper_'.Str::lower(Str::random(8)).'@example.test',
                'password'              => 'correct horse battery staple',
                'password_confirmation' => 'correct horse battery staple',
            ]);

            $resp->assertStatus(409);
            $this->assertSame($before, OperatorAccount::query()->count(), 'a guarded founder POST mints no operator account');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
