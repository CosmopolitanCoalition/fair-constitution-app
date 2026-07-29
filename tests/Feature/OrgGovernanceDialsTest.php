<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrgRestructureService;
use App\Services\Organizations\OrgSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Wave 2 items 6a + 6b — the organizational self-governance planes.
 *
 * 6a (operator v3.2 item 0d): org-level dials live on
 * organizations.settings — NEVER constitutional values — behind a closed
 * key registry with bounds, role-gated to the agent or a seated board
 * member, every change on the audit chain.
 *
 * 6b: internal restructuring is the OWNERS' act. The consent threshold
 * comes from the CURRENT structure's own rules, consent is per holder, and
 * the consent that meets the rule adopts the new structure in the same
 * act. Structure history persists.
 */
class OrgGovernanceDialsTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'org_dials_pg';

    // ── 6a: the settings dial ────────────────────────────────────────────

    public function test_the_key_registry_is_closed(): void
    {
        $this->onLivePg(function () {
            [$org, $agent] = $this->orgFixture('equal_partnership');

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/Unknown organization setting/');

            app(OrgSettingsService::class)->set($org, 'admin_backdoor', 1, $agent);
        });
    }

    public function test_the_window_is_bounded_and_the_agent_may_set_it(): void
    {
        $this->onLivePg(function () {
            [$org, $agent] = $this->orgFixture('equal_partnership');
            $service = app(OrgSettingsService::class);

            try {
                $service->set($org, 'board_nomination_window_days', 400, $agent);
                $this->fail('400 days must exceed the bound');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('between 1 and 90', $e->getMessage());
            }

            $service->set($org, 'board_nomination_window_days', 14, $agent);

            $this->assertSame(14, $service->get($org->refresh(), 'board_nomination_window_days'));

            // The change is a public act — it landed on the audit chain.
            $this->assertTrue(
                DB::table('audit_log')
                    ->where('event', 'org.setting_changed')
                    ->where('payload->organization_id', (string) $org->id)
                    ->exists(),
                'an org setting change must append to the audit chain'
            );
        });
    }

    public function test_an_outsider_may_not_turn_the_dials(): void
    {
        $this->onLivePg(function () {
            [$org] = $this->orgFixture('equal_partnership');
            $stranger = $this->user();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/agent or a seated board member/');

            app(OrgSettingsService::class)->set($org, 'board_nomination_window_days', 7, $stranger);
        });
    }

    // ── 6b: restructuring ────────────────────────────────────────────────

    public function test_equal_partnership_requires_unanimity_and_adopts_in_the_meeting_act(): void
    {
        $this->onLivePg(function () {
            [$org, , $holders] = $this->orgFixture('equal_partnership', holders: 2);
            $service = app(OrgRestructureService::class);

            $proposed = $service->propose($org, 'worker_owned', $holders[0]);

            // 1 of 2 under unanimity — not adopted, structure unchanged.
            $this->assertSame('proposed', $proposed['status']);
            $this->assertSame('equal_partnership', (string) $org->refresh()->structure);

            $consented = $service->consent($proposed['restructure_id'], $holders[1]);

            // The consent that met the rule adopted — in the same act.
            $this->assertSame('adopted', $consented['status']);
            $this->assertSame('worker_owned', (string) $org->refresh()->structure);

            // History persists: the proposal row stays, adopted and dated.
            $row = DB::table('org_restructures')->where('id', $proposed['restructure_id'])->first();
            $this->assertSame('adopted', $row->status);
            $this->assertNotNull($row->adopted_at);
        });
    }

    public function test_stock_counts_units_not_heads(): void
    {
        $this->onLivePg(function () {
            [$org, , $holders] = $this->orgFixture('stock', holders: 3, units: ['60', '30', '10']);
            $service = app(OrgRestructureService::class);

            // The 60-unit holder proposes: 60 of 100 units > half — adopted
            // at once, though only 1 of 3 HEADS consented. Units decide.
            $proposed = $service->propose($org, 'member_owned', $holders[0]);

            $this->assertSame('adopted', $proposed['status'], 'a stock structure counts voting shares, not heads');
            $this->assertSame('member_owned', (string) $org->refresh()->structure);
        });
    }

    public function test_a_non_holder_cannot_restructure_and_consent_is_once(): void
    {
        $this->onLivePg(function () {
            [$org, , $holders] = $this->orgFixture('equal_partnership', holders: 3);
            $service = app(OrgRestructureService::class);
            $stranger = $this->user();

            try {
                $service->propose($org, 'stock', $stranger);
                $this->fail('a non-holder must not propose');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('holder of an ownership stake', $e->getMessage());
            }

            $proposed = $service->propose($org, 'stock', $holders[0]);

            try {
                $service->consent($proposed['restructure_id'], $holders[0]);
                $this->fail('proposing consented — twice is refused');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('already on the record', $e->getMessage());
            }
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────

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

    /**
     * @param  array<int, string>|null  $units
     * @return array{0: Organization, 1: User, 2: array<int, User>}
     */
    private function orgFixture(string $structure, int $holders = 1, ?array $units = null): array
    {
        $root = DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->first();

        if ($root === null) {
            $this->markTestSkipped('no root jurisdiction on this box');
        }

        $agent = $this->user();

        $orgId = (string) Str::uuid();
        DB::table('organizations')->insert([
            'id'              => $orgId,
            'jurisdiction_id' => $root->id,
            'type'            => 'business',
            'name'            => 'Dials Test Org ' . substr($orgId, 0, 8),
            'slug'            => 'dials-test-' . substr($orgId, 0, 8),
            'structure'       => $structure,
            'agent_user_id'   => (string) $agent->id,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $holderUsers = [];
        for ($i = 0; $i < $holders; $i++) {
            $holder = $this->user();
            $holderUsers[] = $holder;

            DB::table('org_ownership_stakes')->insert([
                'id'              => (string) Str::uuid(),
                'organization_id' => $orgId,
                'holder_type'     => 'users',
                'holder_id'       => (string) $holder->id,
                'units'           => $units[$i] ?? '1',
                'acquired_via'    => 'founding',
                'as_of'           => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        return [Organization::query()->findOrFail($orgId), $agent, $holderUsers];
    }

    private function user(): User
    {
        $id = (string) Str::uuid();

        DB::table('users')->insert([
            'id'                => $id,
            'name'              => 'Dials ' . substr($id, 0, 8),
            'email'             => "dials-{$id}@test.invalid",
            'password'          => 'x',
            'terms_accepted_at' => now(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return User::query()->findOrFail($id);
    }
}
