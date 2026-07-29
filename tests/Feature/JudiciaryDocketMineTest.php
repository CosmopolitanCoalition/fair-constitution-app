<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * /judiciary/docket — the whole-chain aggregate (V3 §8 punch). The old resolver
 * collapsed to a single court and 302'd to /civic when the viewer's chain held
 * no court — a fresh resident was bounced away from their own docket. Now the
 * page always renders: an aggregate over every court in the chain, with an
 * honest empty state instead of a redirect.
 *
 * This pins the bug fix: a logged-in user with no court gets a 200 aggregate
 * docket, NOT a 302 to /civic.
 */
class JudiciaryDocketMineTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_docket_mine';

    public function test_a_resident_with_no_court_gets_the_docket_not_a_civic_bounce(): void
    {
        $this->onLivePg(function () {
            $user = User::create([
                'name' => 'Docket Reader '.Str::random(5),
                'email' => 'docket-mine-'.Str::uuid().'@test.invalid',
                'password' => Str::random(32),
                'terms_accepted_at' => now(),
            ]);

            $this->actingAs($user)
                ->get('/judiciary/docket')
                ->assertOk() // the fix: a 200 render, never a 302 to /civic
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Judiciary/CaseDocket')
                    ->where('aggregate', true)
                    ->where('can.fileCase', false) // aggregate is read-only; filing is per-court
                    ->has('cases', 0)              // no court in the chain yet
                    ->has('courts', 0));
        });
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
