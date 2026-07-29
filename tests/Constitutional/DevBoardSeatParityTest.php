<?php

namespace Tests\Constitutional;

use App\Http\Middleware\DevToolsEnabled;
use App\Support\GameMode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the dev board-seat pair (POST /dev/board/seat ↔
 * `dev:board-seat`), closing the last lane-4 parity row.
 *
 * The census proposed hanging the CLI half off `dev:assume`; that service is
 * pinned to NEVER seat (AssumeNeverCreatesOrSeatsTest), so the CLI half is a
 * STANDALONE command instead, and both doors share `DevBoardSeatService` and the
 * SAME `DevToolsEnabled` gate.
 *
 * THE INVARIANTS:
 *   · the gate travels — the command refuses off a local sandbox exactly as the
 *     web route 404s (guards travel with the pair, never the lenient door)
 *   · seat then unseat round-trips the board membership through the shared service
 *   · the web route carries the DevToolsEnabled middleware
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class DevBoardSeatParityTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_boardseat';

    protected function setUp(): void
    {
        parent::setUp();
        // The /dev gate is local + sandbox + toggle. phpunit boots APP_ENV=testing,
        // so force the local sandbox the tool legitimately lives in (the idiom
        // DevImpersonationTest uses).
        $this->app['env'] = 'local';
        config(['cga.impersonation' => true]);
        GameMode::override(GameMode::SANDBOX);
    }

    protected function tearDown(): void
    {
        GameMode::override(null);
        GameMode::flush();
        parent::tearDown();
    }

    /** The gate travels: the command refuses on the same conditions the route 404s. */
    public function test_the_command_refuses_off_a_local_sandbox(): void
    {
        // A production world: the seat would be a real governance write. Refuse.
        GameMode::override(GameMode::PRODUCTION);
        $this->assertFalse(DevToolsEnabled::allowed(), 'a production world must close the gate');
        $this->assertSame(
            1,
            Artisan::call('dev:board-seat', ['legislature' => (string) Str::uuid(), '--user' => (string) Str::uuid()]),
            'dev:board-seat must refuse when the dev gate is closed'
        );

        // Off a local checkout: refuse regardless.
        GameMode::override(GameMode::SANDBOX);
        $this->app['env'] = 'production';
        $this->assertFalse(DevToolsEnabled::allowed(), 'outside local the gate is closed');
    }

    /** Seat then unseat, driven by the CLI through the shared service. */
    public function test_the_command_seats_and_unseats_through_the_shared_service(): void
    {
        $this->onLivePg(function () {
            [$legislatureId, $userId] = $this->boardFixture();

            $this->assertSame(
                0,
                Artisan::call('dev:board-seat', ['legislature' => $legislatureId, '--user' => $userId]),
                'seating must succeed on a local sandbox'
            );
            $this->assertSame(
                1,
                DB::table('election_board_members')
                    ->where('user_id', $userId)->where('status', 'seated')->whereNull('deleted_at')->count(),
                'a seated board membership must exist after the command'
            );

            $this->assertSame(
                0,
                Artisan::call('dev:board-seat', ['legislature' => $legislatureId, '--user' => $userId, '--unseat' => true]),
                'unseating must succeed'
            );
            $this->assertSame(
                0,
                DB::table('election_board_members')
                    ->where('user_id', $userId)->where('status', 'seated')->whereNull('deleted_at')->count(),
                'unseat must soft-delete the membership — R-08 and BoardProvenance exclude it'
            );
        });
    }

    /** An unknown legislature is a plain refusal, not an exception. */
    public function test_an_unknown_legislature_refuses_cleanly(): void
    {
        $this->onLivePg(function () {
            $userId = $this->makeUser(); // a real user, so the refusal is about the legislature

            $this->assertSame(
                1,
                Artisan::call('dev:board-seat', ['legislature' => (string) Str::uuid(), '--user' => $userId]),
                'an unknown legislature must fail cleanly, not throw'
            );
        });
    }

    /** The web door carries the same gate — driving is never a public act. */
    public function test_the_web_route_is_dev_gated(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => str_contains($r->uri(), 'board/seat'));

        $this->assertNotNull($route, 'the /dev/board/seat route must exist');
        $this->assertContains(
            DevToolsEnabled::class,
            $route->gatherMiddleware(),
            'the web board-seat door must carry the DevToolsEnabled gate'
        );
    }

    /** A legislature with an active board and a resident user, on live pg. */
    private function boardFixture(): array
    {
        $jid = (string) Str::uuid();
        DB::table('jurisdictions')->insert([
            'id' => $jid,
            'name' => 'Board Pin',
            'slug' => 'board-pin-'.Str::lower(Str::random(10)),
            'adm_level' => 3,
            'population' => 50_000,
            'source' => 'user_defined',
            'official_languages' => '["en"]',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legId = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id' => $legId,
            'jurisdiction_id' => $jid,
            'term_number' => 1,
            'status' => 'forming',
            'total_seats' => 9,
            'type_a_seats' => 9,
            'type_b_seats' => 0,
            'quorum_required' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('election_boards')->insert([
            'id' => (string) Str::uuid(),
            'jurisdiction_id' => $jid,
            'is_bootstrap' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$legId, $this->makeUser()];
    }

    private function makeUser(): string
    {
        $userId = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Board Pin User',
            'email' => 'board-pin-'.Str::lower(Str::random(10)).'@demo.invalid',
            'password' => bcrypt('x'),
            'locale' => 'en',
            'terms_accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
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
