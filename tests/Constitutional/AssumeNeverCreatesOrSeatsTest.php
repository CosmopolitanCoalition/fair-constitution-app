<?php

namespace Tests\Constitutional;

use App\Services\Dev\AssumeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — D4's two rules (the assume-a-role composition).
 *
 *   IT NEVER CREATES USERS. Creation belongs to the seeders under
 *   GuardsSyntheticData; a walkthrough control that mints people would
 *   let a "playtest" fabricate a population one persona at a time.
 *
 *   IT NEVER SEATS ANYONE. A seat exists because an election,
 *   appointment or registration put someone in it. A control that could
 *   counterfeit a seat is the chamber-cast outcome pin's sibling: the
 *   whole value of "assume a legislator" is that a legislator must
 *   actually exist.
 *
 * The one write it may perform is DEV RELOCATION (residency-derived
 * roles only), and that runs the REAL pipeline — engine filings, audit
 * chain, the works.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class AssumeNeverCreatesOrSeatsTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_assume';

    private function serviceSource(): string
    {
        return file_get_contents((new ReflectionClass(AssumeService::class))->getFileName());
    }

    /** THE PIN — no user creation, no seat writes, anywhere in the service. */
    public function test_the_service_source_cannot_create_users_or_write_seats(): void
    {
        $source = $this->serviceSource();

        $forbidden = [
            'User::create' => 'users are made by seeders under GuardsSyntheticData, never here',
            "'users')->insert" => 'users are made by seeders, never here',
            'factory(' => 'no factory-minted personas',
            "legislature_members')->insert" => 'a chamber seat comes from an election',
            "judicial_seats')->insert" => 'a bench seat comes from an appointment',
            "election_board_members')->insert" => 'a board seat comes from constitution or election',
            "advocates')->insert" => 'the bar comes from registration (F-IND-015)',
            "candidacies')->insert" => 'a candidacy comes from F-IND-011',
        ];

        foreach ($forbidden as $needle => $why) {
            $this->assertStringNotContainsString(
                $needle,
                $source,
                "AssumeService must not contain [{$needle}] — {$why}."
            );
        }
    }

    /** Relocation may only ever touch the reserved synthetic namespace. */
    public function test_relocation_only_picks_from_the_synthetic_namespace(): void
    {
        $source = $this->serviceSource();

        $this->assertStringContainsString(
            "'%@demo.invalid'",
            $source,
            'the relocation pick must be scoped to the reserved synthetic namespace — '
            .'never a real account, even on a dev box'
        );

        $this->assertSame(
            ['R-03', 'R-04'],
            AssumeService::RELOCATABLE_ROLES,
            'only the residency-derived roles are relocatable — everything else is find-only'
        );
    }

    /** The route rides the STRONG gate — relocation writes residency records. */
    public function test_the_route_sits_behind_the_time_controls_gate(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertMatchesRegularExpression(
            "/DevTimeControlsEnabled::class, 'auth'\]\)->prefix\('dev'\)->name\('dev\.'\)->group\(function \(\) \{(?:(?!\}\);).)*Route::post\('\/assume'/s",
            $routes,
            'POST /dev/assume must register inside the DevTimeControlsEnabled group — '
            .'the toolbox gate is not enough for a control that writes residency on demand'
        );
    }

    /**
     * Behavioural: a seated role is FOUND when a seat exists, REFUSED with
     * the manufacture sentence when none does — and the user count never
     * moves. Fixtures create what they measure (the shared-box rule).
     */
    public function test_seated_roles_are_found_or_refused_never_manufactured(): void
    {
        $this->onLivePg(function () {
            $assume = app(AssumeService::class);

            [$jid, $userId] = $this->seatedLegislatureFixture();
            $usersBefore = DB::table('users')->count();

            $found = $assume->findOrRelocate($jid, 'R-09');
            $this->assertSame('found', $found['how']);
            $this->assertSame($userId, (string) $found['user']->id, 'the seated member is the answer');

            try {
                $assume->findOrRelocate($jid, 'R-19');
                $this->fail('an empty bench must refuse, not manufacture');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('cannot be manufactured', $e->getMessage());
            }

            $this->assertSame(
                $usersBefore,
                DB::table('users')->count(),
                'NEVER CREATES USERS — the count must not move, found or refused'
            );
        });
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} [jurisdictionId, seatedUserId] */
    private function seatedLegislatureFixture(): array
    {
        $jid = (string) Str::uuid();
        DB::table('jurisdictions')->insert([
            'id' => $jid,
            'name' => 'Assume Pin',
            'slug' => 'assume-pin-'.Str::lower(Str::random(10)),
            'adm_level' => 3,
            'population' => 1000,
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
            'status' => 'active',
            'total_seats' => 5,
            'type_a_seats' => 5,
            'type_b_seats' => 0,
            'quorum_required' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Assume Pin Member',
            'email' => 'assume-pin-'.Str::lower(Str::random(8)).'@demo.invalid',
            'password' => 'unusable',
            'terms_accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('legislature_members')->insert([
            'id' => (string) Str::uuid(),
            'legislature_id' => $legId,
            'user_id' => $userId,
            'seat_type' => 'a',
            'seat_no' => 1,
            'status' => 'seated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$jid, $userId];
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
