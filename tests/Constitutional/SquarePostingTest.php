<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\User;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-3 (K3-G). RESIDENCY is the ONLY gate on posting in a jurisdiction's
 * public square (Art. I) — a resident posts, a non-resident is refused, never any karma/age/
 * reputation gate. The post is sent as the pseudonymous @u-<handle>, NEVER the legal name. The
 * cga.acting_seat annotation is derived LIVE from current roles at send time (a vacated office loses
 * it). The Matrix client is mocked; residency + RoleService are real (live-pg).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class SquarePostingTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k3_posting';

    public function test_residency_is_the_only_gate_a_resident_posts_a_non_resident_is_refused(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            $resident = $this->resident($jur);
            $stranger = $this->bareUser();

            $sent = null;
            $this->mock(MatrixClientService::class, function ($m) use (&$sent) {
                $m->shouldReceive('sendMessage')->andReturnUsing(function ($room, $content, $asUser = null) use (&$sent) {
                    $sent = ['content' => $content, 'asUser' => $asUser];

                    return ['event_id' => '$e'];
                });
            });
            app(RoleService::class)->flush();
            $gate = app(MatrixPostingGateService::class);

            // A resident of the jurisdiction may post.
            $gate->post($resident, $jur, '!room:localhost', 'more shade trees please');
            $this->assertSame('more shade trees please', $sent['content']['body']);
            $this->assertStringStartsWith('@u-', (string) $sent['asUser'], 'posted as a pseudonymous namespaced user');

            // A non-resident is refused — residency is the ONLY gate (Art. I).
            $threw = false;
            try {
                $gate->post($stranger, $jur, '!room:localhost', 'i am not from here');
            } catch (ConstitutionalViolation $e) {
                $threw = true;
                $this->assertSame('Art. I', $e->citation);
            }
            $this->assertTrue($threw, 'a non-resident cannot post — residency is the only gate');
        });
    }

    public function test_the_post_is_pseudonymous_never_the_legal_name(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            $resident = $this->resident($jur);

            $asUser = null;
            $this->mock(MatrixClientService::class, function ($m) use (&$asUser) {
                $m->shouldReceive('sendMessage')->andReturnUsing(function ($room, $content, $u = null) use (&$asUser) {
                    $asUser = $u;

                    return ['event_id' => '$e'];
                });
            });
            app(RoleService::class)->flush();

            app(MatrixPostingGateService::class)->post($resident, $jur, '!r:localhost', 'hi');
            $this->assertStringStartsWith('@u-', (string) $asUser);
            $this->assertStringNotContainsString($resident->name, (string) $asUser, 'the mxid is never the legal name');
        });
    }

    public function test_acting_seat_is_derived_live_from_current_roles(): void
    {
        $user = new User();

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
            'name'              => 'K3 Legal Name '.Str::uuid(),
            'email'             => 'k3-'.Str::uuid().'@test.invalid',
            'password'          => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    private function resident(string $jurisdictionId): User
    {
        $user = $this->bareUser();

        DB::table('residency_confirmations')->insert([
            'id'              => (string) Str::uuid(),
            'user_id'         => $user->id,
            'jurisdiction_id' => $jurisdictionId,
            'days_confirmed'  => 30,
            'confirmed_at'    => now(),
            'is_active'       => true,
            'depth'           => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
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
