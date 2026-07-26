<?php

namespace Tests\Constitutional;

use App\Services\Demo\PersonaFactory;
use App\Services\Demo\Stages\CohortStage;
use App\Services\Demo\Stages\IdentityStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the IDENTITIES stage.
 *
 * THE SIZING INVARIANT IS THE WHOLE POINT. A literal reading of the charter
 * materializes ~8.35 billion residents and ~42.3 billion residency rows —
 * terabytes before a ballot, and ~9 years of serialized audit appends at the
 * measured ~28.6/sec. Individual identity is only REQUIRED where the
 * constitution demands it: `legislature_members.user_id` is NOT NULL and a
 * winner must have been a candidate, so the mandatory population is
 * Σ(seats + 1) per race. Everyone else is a cohort statistic, counted exactly
 * rather than approximately (WeightedBallotIdentityTest).
 *
 * THE INVARIANTS:
 *   · a roster is sized to what the jurisdiction NEEDS, never to its population
 *   · deterministic — the same seed rebuilds the same people
 *   · idempotent — a re-handed unit TOPS UP, never duplicates
 *   · synthetic identities live in the reserved `@demo.invalid` namespace and
 *     are UNLOGGABLE: a public demo must never ship a usable door
 *   · residency is written directly and honestly (is_active, rights active),
 *     never by filing 30 pings per person that are then deleted
 *   · an unlawful type_b half needs no candidates — per-kind blocking (07-25)
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class IdentityStageTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_identity';

    /** A city of five million gets a working roster, not five million rows. */
    public function test_the_roster_is_sized_to_need_not_to_population(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(population: 5_000_000);
            $this->legislature($jid, typeA: 14, typeB: 5, districtSeats: [7, 7]);
            CohortStage::run($jid, null, 1, 62);

            // Σ(seats+1) over two 7-seat districts = 16, plus the lawful
            // 5-seat at-large type_b race (+6) = 22.
            $this->assertSame(22, IdentityStage::rosterSize($jid));

            $result = IdentityStage::run($jid, null, 1);

            $this->assertSame(22, $result['users']);
            $this->assertLessThan(
                1000,
                $result['users'],
                'a metropolis must NOT mint a person per resident — that is the whole design'
            );
        });
    }

    /** An unlawful type_b half elects nobody, so it needs no candidates. */
    public function test_an_unlawful_type_b_half_adds_no_candidates(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(population: 3_000_000);
            // type_b 1,141 — Earth's real value, far above the per-race maximum.
            $this->legislature($jid, typeA: 14, typeB: 1141, districtSeats: [7, 7]);
            CohortStage::run($jid, null, 1, 62);

            $this->assertSame(
                16,
                IdentityStage::rosterSize($jid),
                'only the lawful district races need candidates; the blocked half needs none'
            );
        });
    }

    /** A place with no legislature still gets faces — a visitor may look at it. */
    public function test_a_jurisdiction_with_no_legislature_still_gets_a_visible_sample(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(population: 40_000);
            CohortStage::run($jid, null, 1, 62);

            $this->assertSame(IdentityStage::VISIBLE_SAMPLE, IdentityStage::rosterSize($jid));

            $result = IdentityStage::run($jid, null, 1);
            $this->assertSame(IdentityStage::VISIBLE_SAMPLE, $result['users']);
        });
    }

    /** Same seed, same people — what makes revert a re-derivation. */
    public function test_identity_generation_is_deterministic(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(population: 80_000);
            CohortStage::run($jid, null, 1, 62);
            IdentityStage::run($jid, null, 1);

            $first = DB::table('residency_confirmations as rc')
                ->join('users as u', 'u.id', '=', 'rc.user_id')
                ->where('rc.jurisdiction_id', $jid)
                ->orderBy('u.email')
                ->pluck('u.name', 'u.email')
                ->all();

            // Wipe the people, keep the cohort, regenerate.
            DB::table('users')->whereIn(
                'id',
                DB::table('residency_confirmations')->where('jurisdiction_id', $jid)->pluck('user_id')
            )->delete();
            DB::table('residency_confirmations')->where('jurisdiction_id', $jid)->delete();

            IdentityStage::run($jid, null, 1);

            $second = DB::table('residency_confirmations as rc')
                ->join('users as u', 'u.id', '=', 'rc.user_id')
                ->where('rc.jurisdiction_id', $jid)
                ->orderBy('u.email')
                ->pluck('u.name', 'u.email')
                ->all();

            $this->assertSame($first, $second, 'the same seed must rebuild the same people');
        });
    }

    /** The pump re-hands a dead worker's unit; that must top up, not duplicate. */
    public function test_re_running_tops_up_rather_than_duplicating(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(population: 60_000);
            CohortStage::run($jid, null, 1, 62);

            $a = IdentityStage::run($jid, null, 1);
            $b = IdentityStage::run($jid, null, 1);

            $this->assertGreaterThan(0, $a['users']);
            $this->assertSame(0, $b['users'], 'a complete roster mints nobody new');
            $this->assertSame($a['users'], $b['reused']);

            $this->assertSame(
                $a['users'],
                DB::table('residency_confirmations')->where('jurisdiction_id', $jid)->count(),
                'and the roster must not have doubled'
            );
        });
    }

    /**
     * A public demo must never ship a usable door. The @cga.test seeders use a
     * known password ON PURPOSE for dev impersonation; these are the opposite.
     */
    public function test_synthetic_identities_are_reserved_namespace_and_unloggable(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(population: 30_000);
            CohortStage::run($jid, null, 1, 62);
            IdentityStage::run($jid, null, 1);

            $users = DB::table('users as u')
                ->join('residency_confirmations as rc', 'rc.user_id', '=', 'u.id')
                ->where('rc.jurisdiction_id', $jid)
                ->select('u.email', 'u.password')
                ->get();

            $this->assertGreaterThan(0, $users->count());

            foreach ($users as $u) {
                $this->assertStringEndsWith('@demo.invalid', $u->email, 'reserved namespace only');
                $this->assertFalse(
                    password_verify('demo', $u->password),
                    'a synthetic identity must not be loggable with a guessable password'
                );
                $this->assertFalse(password_verify('password', $u->password));
                $this->assertFalse(password_verify('', $u->password));
            }
        });
    }

    /** Residency is written honestly — the constitutional gate reads these rows. */
    public function test_residency_is_written_active_with_rights(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(population: 20_000);
            CohortStage::run($jid, null, 1, 62);
            IdentityStage::run($jid, null, 1);

            $rows = DB::table('residency_confirmations')->where('jurisdiction_id', $jid)->get();

            foreach ($rows as $r) {
                $this->assertTrue((bool) $r->is_active);
                $this->assertTrue((bool) $r->voting_right_active);
                $this->assertTrue((bool) $r->candidacy_right_active);
                $this->assertSame(0, (int) $r->depth, 'depth 0 — the ancestor sweep is the 42.3B-row trap');
            }

            $this->assertSame(
                0,
                DB::table('location_pings')->count(),
                'residency must NOT be filed via 30 pings per person that are then deleted'
            );
        });
    }

    /** Personas follow the jurisdiction's languages, not a global default. */
    public function test_personas_follow_the_jurisdictions_languages(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(population: 500_000, languages: ['ar']);
            CohortStage::run($jid, null, 1, 62);
            IdentityStage::run($jid, null, 1);

            $locales = DB::table('users as u')
                ->join('residency_confirmations as rc', 'rc.user_id', '=', 'u.id')
                ->where('rc.jurisdiction_id', $jid)
                ->pluck('u.locale')
                ->unique()
                ->values()
                ->all();

            $this->assertSame(['ar'], $locales, 'an Arabic-speaking place gets Arabic-speaking people');
        });
    }

    /** The persona's provenance travels with it, so defaults are never mistaken for research. */
    public function test_persona_source_is_recorded(): void
    {
        $persona = PersonaFactory::make('seed', ['es'], 'urban', 0);

        $this->assertSame(PersonaFactory::SOURCE_DEFAULT, $persona['persona_source']);
        $this->assertSame('es', $persona['locale']);
        $this->assertNotSame('', trim($persona['name']));
    }

    /**
     * REGRESSION — the bug that produced a silently empty world.
     *
     * A demo instance is the real standard "broadly materialized", so it can
     * legitimately already contain REAL residents: a founded fixture, an
     * imported world, a partially-played instance. The stage originally counted
     * EVERY active resident to decide whether its roster was complete, so
     * someone else's residents filled the quota and it minted NOBODY — while
     * reporting `done`. On the San Marino fixture that was 11 items done and 0
     * people, and the failure was invisible because every item succeeded.
     *
     * The engine must count only ITS OWN people.
     */
    public function test_pre_existing_real_residents_do_not_suppress_minting(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(population: 100_000);
            CohortStage::run($jid, null, 1, 62);

            // Real residents of this place, of the kind a founded world has —
            // each their own person, because residency is unique per user.
            $rows = [];
            for ($i = 0; $i < IdentityStage::VISIBLE_SAMPLE * 3; $i++) {
                $realUser = (string) Str::uuid();

                DB::table('users')->insert([
                    'id' => $realUser,
                    'name' => 'A Real Person '.$i,
                    'email' => 'real-person-'.$i.'-'.Str::lower(Str::random(6)).'@example.test',
                    'password' => bcrypt(Str::random(20)),
                    'status' => 'registered',
                    'terms_accepted_at' => now(),
                    'languages' => '["en"]',
                    'timezone' => 'UTC',
                    'locale' => 'en',
                    'comm_prefs' => '{}',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'user_id' => $realUser,
                    'jurisdiction_id' => $jid,
                    'days_confirmed' => 30,
                    'confirmed_at' => now(),
                    'voting_right_active' => true,
                    'candidacy_right_active' => true,
                    'is_active' => true,
                    'depth' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('residency_confirmations')->insert($rows);

            $result = IdentityStage::run($jid, null, 1);

            $this->assertSame(
                IdentityStage::VISIBLE_SAMPLE,
                $result['users'],
                'residents belonging to someone else must NOT satisfy this engine roster'
            );
            $this->assertSame(0, $result['reused'], 'nothing of ours existed yet');

            $mine = DB::table('residency_confirmations as rc')
                ->join('users as u', 'u.id', '=', 'rc.user_id')
                ->where('rc.jurisdiction_id', $jid)
                ->where('u.email', 'like', 'sim-%@demo.invalid')
                ->count();

            $this->assertSame(IdentityStage::VISIBLE_SAMPLE, $mine);
        });
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function jurisdiction(int $population, array $languages = ['en']): string
    {
        $id = (string) Str::uuid();

        DB::table('jurisdictions')->insert([
            'id' => $id,
            'name' => 'Identity Pin',
            'slug' => 'identity-pin-'.Str::lower(Str::random(10)),
            'adm_level' => 4,
            'population' => $population,
            'source' => 'user_defined',
            'official_languages' => json_encode($languages),
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param list<int> $districtSeats */
    private function legislature(string $jid, int $typeA, int $typeB, array $districtSeats = []): string
    {
        $id = (string) Str::uuid();

        DB::table('legislatures')->insert([
            'id' => $id,
            'jurisdiction_id' => $jid,
            'term_number' => 1,
            'status' => 'forming',
            'total_seats' => $typeA + $typeB,
            'type_a_seats' => $typeA,
            'type_b_seats' => $typeB,
            'quorum_required' => max(3, (int) ceil(($typeA + $typeB) / 2)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($districtSeats !== []) {
            $mapId = (string) Str::uuid();
            DB::table('legislature_district_maps')->insert([
                'id' => $mapId,
                'legislature_id' => $id,
                'name' => 'Pin Map',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($districtSeats as $i => $seats) {
                DB::table('legislature_districts')->insert([
                    'id' => (string) Str::uuid(),
                    'legislature_id' => $id,
                    'map_id' => $mapId,
                    'jurisdiction_id' => $jid,
                    'district_number' => $i + 1,
                    'seats' => $seats,
                    'target_population' => 1_000_000,
                    'actual_population' => 1_000_000,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $id;
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
