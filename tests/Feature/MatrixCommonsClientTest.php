<?php

namespace Tests\Feature;

use App\Models\MatrixRoom;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\Matrix\MatrixClientService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-3 (K3-L), the embedded Matrix commons client. Two invariants the compile
 * cannot catch: (1) a down/unreachable homeserver DEGRADES to an empty timeline (reachable=false), never
 * a 500; (2) the rendered timeline is PSEUDONYMOUS — senders are @u-<handle> mxids, the legal name never
 * appears. The MatrixClientService is mocked; the controller + Inertia resolve end-to-end (live-pg).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class MatrixCommonsClientTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k3_commons';

    public function test_a_down_homeserver_degrades_to_an_empty_timeline_not_a_500(): void
    {
        $this->onLivePg(function () {
            [$user, $jur] = $this->residentWithSquareRoom('Resident LEGALNAME One');
            $this->mock(MatrixClientService::class, fn ($m) => $m->shouldReceive('getMessages')->andThrow(new \RuntimeException('homeserver down')));
            app(RoleService::class)->flush();

            $this->actingAs($user)->get('/civic/commons/square')
                ->assertStatus(200)
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/MatrixCommons')
                    ->where('reachable', false)
                    ->where('messages', [])
                    ->where('spaceType', MatrixRoom::SPACE_PUBLIC_SQUARE)
                );
        });
    }

    public function test_the_timeline_is_pseudonymous_never_the_legal_name(): void
    {
        $this->onLivePg(function () {
            [$user, $jur] = $this->residentWithSquareRoom('Resident LEGALNAME Two');
            $this->mock(MatrixClientService::class, function ($m) {
                $m->shouldReceive('getMessages')->andReturn([
                    'chunk' => [[
                        'type' => 'm.room.message', 'event_id' => '$e1', 'sender' => '@u-alice:localhost',
                        'content' => ['body' => 'hello commons'], 'origin_server_ts' => 1700000000000,
                    ]],
                    'start' => 's', 'end' => 'e',
                ]);
            });
            app(RoleService::class)->flush();

            $this->actingAs($user)->get('/civic/commons/square')
                ->assertStatus(200)
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/MatrixCommons')
                    ->where('reachable', true)
                    // The TIMELINE content is pseudonymous — the sender is an @u-<handle> mxid; the
                    // controller never resolves it to a poster's legal name (it has only the mxid).
                    ->where('messages.0.sender', '@u-alice:localhost')
                    ->where('messages.0.body', 'hello commons')
                    ->where('myMxid', fn ($mxid) => str_starts_with((string) $mxid, '@u-'))
                    // No legal name leaks into the commons content (messages / my identity), even though
                    // the app shell shows the VIEWER their own account name (auth.user, orthogonal).
                    ->where('messages', fn ($messages) => ! str_contains(json_encode($messages), 'LEGALNAME'))
                );
        });
    }

    /** @return array{0: User, 1: string} */
    private function residentWithSquareRoom(string $legalName): array
    {
        $jur = DB::table('jurisdictions')->whereNull('deleted_at')->value('id');
        if ($jur === null) {
            $this->markTestSkipped('Live DB has no jurisdiction.');
        }
        $jur = (string) $jur;

        $user = User::create([
            'name'              => $legalName,
            'email'             => 'k3l-'.Str::uuid().'@test.invalid',
            'password'          => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
        SocialProfile::query()->create(['user_id' => (string) $user->id, 'handle' => 'k3l-'.Str::random(6)]);

        DB::table('residency_confirmations')->insert([
            'id'              => (string) Str::uuid(),
            'user_id'         => (string) $user->id,
            'jurisdiction_id' => $jur,
            'days_confirmed'  => 30,
            'confirmed_at'    => now(),
            'is_active'       => true,
            'depth'           => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        MatrixRoom::query()->create([
            'matrix_room_id' => '!square-'.Str::random(6).':localhost',
            'room_type'      => MatrixRoom::ROOM_COMMONS,
            'room_version'   => '12',
            'entity_type'    => MatrixRoom::ENTITY_JURISDICTION,
            'entity_id'      => $jur,
            'space_type'     => MatrixRoom::SPACE_PUBLIC_SQUARE,
            'is_public'      => true,
        ]);

        app(RoleService::class)->flush();

        return [$user, $jur];
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
