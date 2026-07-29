<?php

namespace Tests\Feature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Design Round 2 build piece 4 — F-ORG-008 share issuance.
 *
 * Shares are equity on the NAMED ownership plane (Ruling B), never a currency
 * (Art. V §5) and never money. Only a STOCK organization has shares to issue;
 * everywhere else ownership is by membership. Issuance routes through the one
 * cap-table writer, recomputes pct, and files through the engine like every
 * other act — a refusal is an answer with a citation.
 */
class OrgShareIssuanceTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'share_issue_pg';

    public function test_a_stock_org_issues_shares_through_the_engine(): void
    {
        $this->onLivePg(function () {
            [$org, $agent] = $this->org('stock');
            $holder = $this->user();

            app(ConstitutionalEngine::class)->file('F-ORG-008', $agent, [
                'action'          => 'issue_shares',
                'organization_id' => (string) $org->id,
                'holder_type'     => 'users',
                'holder_id'       => (string) $holder->id,
                'units'           => 100,
            ]);

            $stake = DB::table('org_ownership_stakes')
                ->where('organization_id', $org->id)
                ->where('holder_id', $holder->id)
                ->whereNull('ended_at')
                ->first();

            $this->assertNotNull($stake, 'issuance opens a cap-table stake');
            $this->assertSame('issue', $stake->acquired_via);
            $this->assertEqualsWithDelta(100.0, (float) $stake->units, 0.0001);
            // Sole holder → 100% after pct recompute.
            $this->assertEqualsWithDelta(100.0, (float) $stake->pct, 0.0001);

            // The user-holder ⇒ membership invariant held.
            $this->assertTrue(
                DB::table('org_memberships')
                    ->where('organization_id', $org->id)
                    ->where('user_id', $holder->id)
                    ->exists(),
                'a share holder becomes a member of the ownership class'
            );
        });
    }

    public function test_a_non_stock_org_cannot_issue_shares(): void
    {
        $this->onLivePg(function () {
            [$org, $agent] = $this->org('member_owned');
            $holder = $this->user();

            try {
                app(ConstitutionalEngine::class)->file('F-ORG-008', $agent, [
                    'action'          => 'issue_shares',
                    'organization_id' => (string) $org->id,
                    'holder_type'     => 'users',
                    'holder_id'       => (string) $holder->id,
                    'units'           => 100,
                ]);
                $this->fail('a member-owned org has no shares to issue');
            } catch (ConstitutionalViolation $e) {
                $this->assertStringContainsString('stock organization', $e->getMessage());
            }

            $this->assertFalse(
                DB::table('org_ownership_stakes')->where('organization_id', $org->id)->where('acquired_via', 'issue')->exists()
            );
        });
    }

    public function test_only_the_agent_may_issue(): void
    {
        $this->onLivePg(function () {
            [$org] = $this->org('stock');
            $stranger = $this->user();

            $this->expectException(ConstitutionalViolation::class);

            app(ConstitutionalEngine::class)->file('F-ORG-008', $stranger, [
                'action'          => 'issue_shares',
                'organization_id' => (string) $org->id,
                'holder_type'     => 'users',
                'holder_id'       => (string) $stranger->id,
                'units'           => 100,
            ]);
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{0: Organization, 1: User} */
    private function org(string $structure): array
    {
        $root = DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->value('id');
        if ($root === null) {
            $this->markTestSkipped('no root jurisdiction on this box');
        }

        $agent = $this->user();
        $orgId = (string) Str::uuid();

        DB::table('organizations')->insert([
            'id'              => $orgId,
            'jurisdiction_id' => $root,
            'type'            => 'business',
            'name'            => 'Issue Test Org ' . substr($orgId, 0, 8),
            'slug'            => 'issue-test-' . substr($orgId, 0, 8),
            'structure'       => $structure,
            'agent_user_id'   => (string) $agent->id,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return [Organization::query()->findOrFail($orgId), $agent];
    }

    private function user(): User
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id'                => $id,
            'name'              => 'Issue ' . substr($id, 0, 8),
            'email'             => "issue-{$id}@test.invalid",
            'password'          => 'x',
            'terms_accepted_at' => now(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return User::query()->findOrFail($id);
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
            $conn->rollBack();
            DB::setDefaultConnection($original);
        }
    }
}
