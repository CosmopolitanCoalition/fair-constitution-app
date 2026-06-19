<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Services\ConstitutionalValidator;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-1 (Art. I — the public square cannot be censored). The ONLY way a
 * public-square / hall post is removed is a LOGGED carve-out: a removal must name a real
 * carve-out (judicial order / rights protection) AND a justifying reference — viewpoint or
 * discretionary removal is structurally unrepresentable (validator). WHO may invoke is the
 * derived judicial office (R-19/R-20), enforced by the engine role gate — a resident cannot.
 * And the local-only social graph (reactions/follows/memberships) can never reach the public
 * register or the chain (FORBIDDEN_SUBJECT_TYPES).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class SocialModerationCarveoutsTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k1_moderation';

    public function test_a_removal_without_a_valid_logged_carve_out_is_refused(): void
    {
        $this->onLivePg(function () {
            $validator = app(ConstitutionalValidator::class);
            $postId = (string) Str::uuid();

            // (a) a viewpoint "carve-out" is not a carve-out.
            $this->assertRefused($validator, ['carve_out' => 'viewpoint', 'reference' => 'they were rude', 'target_post_id' => $postId]);
            // (b) no carve-out at all.
            $this->assertRefused($validator, ['target_post_id' => $postId, 'reference' => 'x']);
            // (c) a real carve-out but NO logged order/reference.
            $this->assertRefused($validator, ['carve_out' => 'judicial_order', 'reference' => '', 'target_post_id' => $postId]);
        });
    }

    public function test_a_well_formed_judicial_carve_out_passes_the_shape_gate(): void
    {
        $this->onLivePg(function () {
            app(ConstitutionalValidator::class)->check('F-SOC-003', [
                'carve_out'      => 'judicial_order',
                'reference'      => 'case:7f3c-ruling-12',
                'target_post_id' => (string) Str::uuid(),
            ]);

            $this->assertTrue(true, 'a logged judicial-order removal passes the carve-out shape gate');
        });
    }

    public function test_only_the_judicial_office_may_invoke_a_removal_not_a_resident(): void
    {
        $this->onLivePg(function () {
            $resident = $this->resident();

            $threw = false;
            try {
                app(ConstitutionalEngine::class)->file('F-SOC-003', $resident, [
                    'carve_out'      => 'judicial_order',
                    'reference'      => 'case:1',
                    'target_post_id' => (string) Str::uuid(),
                ]);
            } catch (ConstitutionalViolation $e) {
                $threw = true;
                $this->assertSame('CGA Roles & Forms Chart', $e->citation, 'gated to the derived judicial office, never a moderator bit');
            }

            $this->assertTrue($threw, 'a mere resident cannot remove a public-square post — only the judicial office (R-19/R-20)');
        });
    }

    public function test_the_local_only_social_graph_can_never_reach_the_public_register(): void
    {
        // The const rail.
        foreach (['social_reaction', 'social_follow', 'social_membership'] as $forbidden) {
            $this->assertContains($forbidden, PublicRecordService::FORBIDDEN_SUBJECT_TYPES);
        }

        // The runtime tripwire: publishing any of them is refused at the single write chokepoint.
        $this->expectException(InvalidArgumentException::class);
        app(PublicRecordService::class)->publish('statement', 'leak attempt', null, [
            'subject_type' => 'social_reaction',
            'subject_id'   => (string) Str::uuid(),
        ]);
    }

    private function assertRefused(ConstitutionalValidator $validator, array $payload): void
    {
        $threw = false;
        try {
            $validator->check('F-SOC-003', $payload);
        } catch (ConstitutionalViolation $e) {
            $threw = true;
            $this->assertSame('Art. I', $e->citation);
        }
        $this->assertTrue($threw, 'a removal without a valid logged carve-out must be refused (Art. I)');
    }

    private function resident(): User
    {
        $jurisdictionId = DB::table('jurisdictions')->whereNull('deleted_at')->value('id');
        if ($jurisdictionId === null) {
            $this->markTestSkipped('Live DB has no jurisdiction.');
        }

        $user = User::create([
            'name'              => 'K1 Mod Resident '.Str::uuid(),
            'email'             => 'k1-mod-'.Str::uuid().'@test.invalid',
            'password'          => Str::random(32),
            'terms_accepted_at' => now(),
        ]);

        DB::table('residency_confirmations')->insert([
            'id'              => (string) Str::uuid(),
            'user_id'         => $user->id,
            'jurisdiction_id' => (string) $jurisdictionId,
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
