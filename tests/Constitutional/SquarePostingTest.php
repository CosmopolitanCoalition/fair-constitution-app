<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\MatrixRoom;
use App\Models\User;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-3 (K3-G) / Phase 5. The public commons is OPEN (Art. I — free movement +
 * equal treatment): a resident AND a visitor (non-resident) may BOTH post in a jurisdiction's public
 * square, never any residency/karma/age/reputation gate on ACCESS. Residency gates governance POWERS,
 * which the game enforces, not room access. The post is sent as the pseudonymous @u-<handle>, NEVER the
 * legal name. The cga.acting_seat annotation is a POWER BADGE derived LIVE from current roles at send
 * time (a vacated office loses it; a visitor with no seat gets none). The Matrix client is mocked;
 * residency + RoleService are real (live-pg).
 *
 * (Corrected 2026-06-27: the prior pin refused a non-resident; the operator's constitutional correction
 * is that the commons is open and only POWERS are residency-gated.)
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class SquarePostingTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k3_posting';

    public function test_the_open_commons_a_resident_and_a_visitor_both_post(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            $room = $this->commonsRoom($jur, MatrixRoom::SPACE_PUBLIC_SQUARE, '!square:localhost');
            $resident = $this->resident($jur);
            $visitor = $this->bareUser(); // NO residency association with this jurisdiction

            $sent = [];
            $this->mock(MatrixClientService::class, function ($m) use (&$sent) {
                $m->shouldReceive('sendMessage')->andReturnUsing(function ($room, $content, $asUser = null) use (&$sent) {
                    $sent[] = ['content' => $content, 'asUser' => $asUser];

                    return ['event_id' => '$e'];
                });
            });
            app(RoleService::class)->flush();
            $gate = app(MatrixPostingGateService::class);

            // A resident of the jurisdiction may post.
            $gate->post($resident, $jur, $room, 'more shade trees please');
            $this->assertSame('more shade trees please', $sent[0]['content']['body']);
            $this->assertStringStartsWith('@u-', (string) $sent[0]['asUser'], 'posted as a pseudonymous namespaced user');

            // A VISITOR (non-resident) may ALSO post — the public commons is open (Art. I).
            $gate->post($visitor, $jur, $room, 'just passing through, but i care about this');
            $this->assertSame('just passing through, but i care about this', $sent[1]['content']['body']);
            $this->assertStringStartsWith('@u-', (string) $sent[1]['asUser'], 'the visitor posts pseudonymously too');

            // Neither carries a power badge — acting_seat is role-derived, not granted by access.
            $this->assertArrayNotHasKey('cga.acting_seat', $sent[0]['content'], 'a plain resident has no seat badge');
            $this->assertArrayNotHasKey('cga.acting_seat', $sent[1]['content'], 'a visitor has no seat badge');
        });
    }

    public function test_the_open_commons_is_scoped_a_non_commons_room_is_refused(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            // An organization's PRIVATE room — it has its OWN controls, not reachable through the commons.
            $orgRoom = MatrixRoom::query()->create([
                'matrix_room_id' => '!org-private:localhost',
                'room_type' => MatrixRoom::ROOM_ORG_PRIVATE,
                'room_version' => '12',
                'entity_type' => MatrixRoom::ENTITY_ORGANIZATION,
                'entity_id' => (string) Str::uuid(),
                'space_type' => null,
                'is_public' => false,
            ]);
            $resident = $this->resident($jur);

            // The Matrix client must NEVER be reached — the gate fails closed before any send.
            $this->mock(MatrixClientService::class, function ($m) {
                $m->shouldReceive('sendMessage')->never();
            });
            app(RoleService::class)->flush();

            $threw = false;
            try {
                app(MatrixPostingGateService::class)->post($resident, $jur, $orgRoom->matrix_room_id, 'i should not be able to post here');
            } catch (ConstitutionalViolation $e) {
                $threw = true;
                $this->assertSame('Art. I', $e->citation);
            }
            $this->assertTrue($threw, 'the open commons is the public square / halls only — org/private rooms have their own controls');

            // And an UNKNOWN room id fails closed too (mirrors the TranslationGate posture).
            $unknownRefused = false;
            try {
                app(MatrixPostingGateService::class)->post($resident, $jur, '!nope:localhost', 'x');
            } catch (ConstitutionalViolation) {
                $unknownRefused = true;
            }
            $this->assertTrue($unknownRefused, 'an unknown room id fails closed');
        });
    }

    public function test_the_post_is_pseudonymous_never_the_legal_name(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            $room = $this->commonsRoom($jur, MatrixRoom::SPACE_PUBLIC_SQUARE, '!square:localhost');
            $resident = $this->resident($jur);

            $asUser = null;
            $this->mock(MatrixClientService::class, function ($m) use (&$asUser) {
                $m->shouldReceive('sendMessage')->andReturnUsing(function ($room, $content, $u = null) use (&$asUser) {
                    $asUser = $u;

                    return ['event_id' => '$e'];
                });
            });
            app(RoleService::class)->flush();

            app(MatrixPostingGateService::class)->post($resident, $jur, $room, 'hi');
            $this->assertStringStartsWith('@u-', (string) $asUser);
            $this->assertStringNotContainsString($resident->name, (string) $asUser, 'the mxid is never the legal name');
        });
    }

    public function test_acting_seat_is_derived_live_from_current_roles(): void
    {
        $user = new User;

        foreach ([
            [['R-03', 'R-09'], 'legislature_member'],
            [['R-03', 'R-10'], 'speaker'],
            [['R-03', 'R-19'], 'judicial'],
            [['R-03', 'R-20'], 'judicial'],
            [['R-03'], null],
        ] as [$roles, $expected]) {
            $this->mock(RoleService::class, fn ($m) => $m->shouldReceive('rolesFor')->andReturn($roles));
            $seat = app(MatrixPostingGateService::class)->actingSeatFor($user);
            $this->assertSame($expected, $seat, 'acting_seat for '.json_encode($roles));
        }
    }

    private function aJurisdiction(): string
    {
        $id = DB::table('jurisdictions')->whereNull('deleted_at')->value('id');
        if ($id === null) {
            $this->markTestSkipped('Live DB has no jurisdiction.');
        }

        return (string) $id;
    }

    private function bareUser(): User
    {
        return User::create([
            'name' => 'K3 Legal Name '.Str::uuid(),
            'email' => 'k3-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    /** A public-commons room bound to the jurisdiction (the open gate resolves + scopes against this). */
    private function commonsRoom(string $jur, string $spaceType, string $matrixRoomId): string
    {
        MatrixRoom::query()->create([
            'matrix_room_id' => $matrixRoomId,
            'room_type' => MatrixRoom::ROOM_COMMONS,
            'room_version' => '12',
            'entity_type' => MatrixRoom::ENTITY_JURISDICTION,
            'entity_id' => $jur,
            'space_type' => $spaceType,
            'is_public' => true,
        ]);

        return $matrixRoomId;
    }

    private function resident(string $jurisdictionId): User
    {
        $user = $this->bareUser();

        DB::table('residency_confirmations')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'jurisdiction_id' => $jurisdictionId,
            'days_confirmed' => 30,
            'confirmed_at' => now(),
            'is_active' => true,
            'depth' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(RoleService::class)->flush();

        return $user;
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
