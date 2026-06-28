<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\SocialThread;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-1 (F-SOC-001, the public square) / Phase 5. The public commons is
 * OPEN (Art. I — free movement + equal treatment): ANY authenticated player may open a thread + post,
 * resident OR visitor, on BOTH planes (the recorded K-1 square here, and the live K-3 Matrix commons).
 * Residency gates governance POWERS (and the testimony SEAL, F-SOC-002), never access. The recorded
 * payload is pseudonymous (author_display, never name/email).
 *
 * (Corrected 2026-06-27: the prior pin refused a non-resident; the operator's constitutional correction
 * opens the recorded square to visitors too — only POWERS stay residency-gated.)
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
                'title' => 'Should the plaza get more shade?',
                'body' => 'More shade trees would help the market days.',
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
            // Pseudonymity (Art. I): author_display is NEVER the legal name. With no pseudonym
            // profile set it is the generated, non-PII pseudonym — not users.name.
            $this->assertNotSame($resident->name, $rec['author_display'], 'author_display must never be the legal name');
            $this->assertStringStartsWith('Resident-', (string) $rec['author_display']);
        });
    }

    public function test_author_display_uses_the_pseudonym_profile_never_the_legal_name(): void
    {
        $this->onLivePg(function () {
            $jurisdictionId = $this->aJurisdiction();
            $resident = $this->resident($jurisdictionId);

            // The resident sets a dedicated pseudonym profile (display_name); name/email stay private.
            SocialProfile::query()->create([
                'user_id' => (string) $resident->getKey(),
                'display_name' => 'PlazaFan',
            ]);

            $rec = app(ConstitutionalEngine::class)->file('F-SOC-001', $resident, [
                'jurisdiction_id' => $jurisdictionId,
                'title' => 'Hello under my handle',
                'body' => 'Posting pseudonymously.',
            ])->recorded;

            $this->assertSame('PlazaFan', $rec['author_display'], 'the chosen pseudonym is used');
            $this->assertNotSame($resident->name, $rec['author_display']);
        });
    }

    public function test_the_open_commons_a_visitor_non_resident_may_post(): void
    {
        $this->onLivePg(function () {
            $jurisdictionId = $this->aJurisdiction();
            $visitor = $this->bareUser();   // authenticated, but NO residency association
            app(RoleService::class)->flush();

            $this->assertNotContains('R-03', app(RoleService::class)->rolesFor($visitor), 'precondition: a visitor, not a resident');

            // The public commons is open (Art. I) — the visitor opens a thread + post, pseudonymously.
            $rec = app(ConstitutionalEngine::class)->file('F-SOC-001', $visitor, [
                'jurisdiction_id' => $jurisdictionId,
                'title' => 'Passing through',
                'body' => 'I am not from here, but I care about this square.',
            ])->recorded;

            $this->assertSame('public_square', $rec['space_type']);
            $this->assertTrue(SocialThread::query()->whereKey($rec['thread_id'])->exists(), 'the visitor opened a thread');
            $this->assertSame(1, SocialPost::query()->where('author_user_id', (string) $visitor->getKey())->count(),
                'the visitor created a post — access is open');

            // Pseudonymity holds for visitors too — the recorded payload never carries the legal name.
            $this->assertArrayNotHasKey('name', $rec);
            $this->assertArrayNotHasKey('email', $rec);
            $this->assertNotSame($visitor->name, $rec['author_display'], 'author_display must never be the legal name');
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
            'name' => 'K1 Stranger '.Str::uuid(),
            'email' => 'k1-stranger-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
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
