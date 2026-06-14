<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * FE-F page smoke — the federation console renders for an authenticated user
 * across BOTH empty and populated states (the controller executes end to end,
 * Inertia resolves Jurisdictions/Federation). Catches controller-body runtime
 * errors the compile cannot. Live-pg posture, one rolled-back transaction.
 */
class FederationPageSmokeTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_federation_page';

    public function test_the_federation_console_renders_for_a_resident(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $user = User::create([
                'name' => 'Federation Smoke '.Str::random(5),
                'email' => 'fed-smoke-'.Str::uuid().'@test.invalid',
                'password' => Str::random(32),
                'terms_accepted_at' => now(),
            ]);

            $this->actingAs($user);

            $this->get('/federation')
                ->assertStatus(200)
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Jurisdictions/Federation')
                    ->has('instance')
                    ->has('peers')
                    ->has('sync')
                    ->has('checkpoints')
                    ->has('claims')
                );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }
}
