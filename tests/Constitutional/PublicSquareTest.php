<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\SocialPost;
use App\Models\SocialThread;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-1 (F-SOC-001, the public square). Art. I: participation in a
 * jurisdiction's public square is RESIDENCY-ONLY (R-03, derived) — never a karma/account-age/
 * reputation gate. A resident opens a thread + post through the engine; a non-resident is
 * refused. The recorded payload is pseudonymous (author_display, never name/email).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class PublicSquareTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k1_square';

    public function test_a_resident_opens_a_thread_and_post_in_the_public_square(): void
    {
        $this->onLivePg(function () {
            $jurisdictionId = $this->aJurisdiction();
            $resident = $this->resident($jurisdictionId);

            // The resident genuinely derives R-03 (residency), the only gate.
            $this->assertContains('R-03', app(RoleService::class)->rolesFor($resident));

            $result = app(ConstitutionalEngine::class)->file('F-SOC-001', $resident, [
                'jurisdiction_id' => $jurisdictionId,
                'title'           => 'Should the plaza get more shade?',
                'body'            => 'More shade trees would help the market days.',
            ]);

            $rec = $result->recorded;
            $this->assertArrayHasKey('thread_id', $rec);
            $this->assertArrayHasKey('post_id', $rec);
            $this->assertSame('public_square', $rec['space_type']);
            $this->assertTrue(SocialThread::query()->whereKey($rec['thread_id'])->exists(), 'the thread was opened');
            $this->assertTrue(SocialPost::query()->whereKey($rec['post_id'])->exists(), 'the post was created');

            // Pseudonymity: the recorded audit payload never carries name/email.
            $this->assertArrayNotHasKey('email', $rec);
            $this->assertArrayNotHasKey('name', $rec);
            $this->assertArrayHasKey('author_display', $rec);
        });
    }

    public function test_residency_is_the_only_gate_a_non_resident_is_refused(): void
    {
        $this->onLivePg(function () {
            $jurisdictionId = $this->aJurisdiction();
            $stranger = $this->bareUser();   // authenticated, but NO residency association
            app(RoleService::class)->flush();

            $this->assertNotContains('R-03', app(RoleService::class)->rolesFor($stranger), 'precondition: no residency');

            $threw = false;
            try {
                app(ConstitutionalEngine::class)->file('F-SOC-001', $stranger, [
                    'jurisdiction_id' => $jurisdictionId,
                    'title'           => 'I am not from here',
                    'body'            => 'but I want to post as a constituent',
                ]);
            } catch (ConstitutionalViolation $e) {
                $threw = true;
                $this->assertSame('CGA Roles & Forms Chart', $e->citation);
                $this->assertStringContainsString('R-03', $e->getMessage());
            }

            $this->assertTrue($threw, 'a non-resident cannot post as a constituent — residency is the only gate (Art. I)');
            $this->assertSame(0, SocialPost::query()->where('author_user_id', (string) $stranger->getKey())->count(),
                'the refused filing created no post');
        });
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
            'name'              => 'K1 Stranger '.Str::uuid(),
            'email'             => 'k1-stranger-'.Str::uuid().'@test.invalid',
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
