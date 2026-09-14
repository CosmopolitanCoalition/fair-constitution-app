<?php

namespace Tests\Constitutional;

use App\Console\Commands\SimPumpCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PIN — the counting-phase mint is bound to the TARGET election of the claim
 * (debt row 43). One election_scope produced exactly one election; the mint
 * must create one count item for THAT election, never one for every open
 * election of the jurisdiction.
 *
 * The mint is raw PostgreSQL (gen_random_uuid, ::text, now, INSERT...SELECT),
 * so it cannot run on the sqlite fixture the handler-driven pins use. It runs
 * on a DISPOSABLE PostgreSQL database, never the world database: a
 * strict-named database created for the test and dropped in teardown, its
 * schema built by the test alone, and a guard that refuses to proceed if the
 * fixture unexpectedly holds the world's tables. The statement under test is
 * SimPumpCommand::countingMintSql() — the exact production SQL, not a copy.
 */
final class SimCountingMintScopeTest extends TestCase
{
    private const CONNECTION = 'sim_count_fixture';

    private ?string $fixtureDb = null;

    private bool $usable = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql not loaded — this pin runs inside the app container.');
        }

        $base = config('database.connections.pgsql');
        if (! is_array($base) || ($base['driver'] ?? null) !== 'pgsql') {
            $this->markTestSkipped('No pgsql connection configured.');
        }

        $this->fixtureDb = 'cga_siminst_'.date('Ymd').'_'.bin2hex(random_bytes(8));
        $this->assertMatchesFixtureName($this->fixtureDb);

        // Maintenance connection on the postgres database creates the fixture.
        config(['database.connections.sim_count_admin' => array_merge($base, ['database' => 'postgres'])]);

        try {
            DB::connection('sim_count_admin')->getPdo();
        } catch (\Throwable $e) {
            $this->fixtureDb = null;
            $this->markTestSkipped('Live PostgreSQL unreachable — run inside the app container. ('.$e->getMessage().')');
        }

        DB::connection('sim_count_admin')->statement('CREATE DATABASE "'.$this->fixtureDb.'" TEMPLATE template0');

        config(['database.connections.'.self::CONNECTION => array_merge($base, ['database' => $this->fixtureDb])]);
        $fixture = DB::connection(self::CONNECTION);

        // GUARD: this must be a private fixture, never the world database.
        $hasWorld = $fixture->selectOne("SELECT to_regclass('public.organizations') AS t")->t;
        $this->assertNull($hasWorld, 'Fixture unexpectedly contains the world schema — refusing.');

        $fixture->statement('CREATE TABLE elections (id uuid PRIMARY KEY, jurisdiction_id uuid, status text)');
        $fixture->statement('CREATE TABLE election_races (id uuid PRIMARY KEY, election_id uuid)');
        $fixture->statement(
            'CREATE TABLE sim_items (
                id uuid PRIMARY KEY,
                run_id uuid NOT NULL,
                kind varchar(24) NOT NULL,
                status varchar(16) NOT NULL,
                jurisdiction_id uuid,
                race_id uuid,
                adm_level smallint,
                unit_key varchar(128) NOT NULL,
                position integer DEFAULT 0,
                est_cost bigint DEFAULT 0,
                metrics jsonb DEFAULT \'{}\',
                created_at timestamptz,
                updated_at timestamptz
            )'
        );

        $this->usable = true;
    }

    protected function tearDown(): void
    {
        if ($this->fixtureDb !== null) {
            try {
                DB::purge(self::CONNECTION);
                DB::connection('sim_count_admin')->statement('DROP DATABASE IF EXISTS "'.$this->fixtureDb.'" WITH (FORCE)');
                DB::purge('sim_count_admin');
            } catch (\Throwable) {
                // A dropped fixture on a flapping engine is harmless — its name
                // is strict and unique to this run.
            }
        }

        parent::tearDown();
    }

    public function test_the_counting_mint_takes_only_the_target_election_of_the_claim(): void
    {
        if (! $this->usable) {
            $this->markTestSkipped('Fixture not usable.');
        }

        $fixture = DB::connection(self::CONNECTION);

        $run = (string) Str::uuid();
        $jur = (string) Str::uuid();
        $electionA = (string) Str::uuid();   // the target — the election this scope produced
        $electionB = (string) Str::uuid();   // a second open election in the SAME jurisdiction
        $now = now();

        // Two open elections in ONE jurisdiction, each carrying a race.
        foreach ([$electionA, $electionB] as $e) {
            $fixture->table('elections')->insert(['id' => $e, 'jurisdiction_id' => $jur, 'status' => 'scheduled']);
            $fixture->table('election_races')->insert(['id' => (string) Str::uuid(), 'election_id' => $e]);
        }

        // The done election_scope carries the election it PRODUCED in race_id
        // (electionA), and is keyed on the jurisdiction in unit_key — exactly
        // what SimWorkerJob stamps at settle.
        $fixture->table('sim_items')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => $run,
            'kind' => 'election_scope',
            'status' => 'done',
            'jurisdiction_id' => $jur,
            'race_id' => $electionA,
            'adm_level' => 2,
            'unit_key' => $jur,
            'position' => 0,
            'est_cost' => 0,
            'metrics' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Run the EXACT production mint statement. Every placeholder binds the
        // run id (the production binding rule).
        $sql = SimPumpCommand::countingMintSql();
        $bindings = array_fill(0, substr_count($sql, '?'), $run);
        $minted = $fixture->affectingStatement($sql, $bindings);

        $counts = $fixture->table('sim_items')->where('kind', 'count_election')->get();

        $this->assertSame(1, $minted, 'exactly one count item is minted');
        $this->assertCount(1, $counts, 'only the target election gets a counting item');
        $this->assertSame($electionA, (string) $counts->first()->race_id, 'the count item is for the target election');
        $this->assertSame($electionA, (string) $counts->first()->unit_key, 'the count item is keyed on the target election');

        // Election B — the other open election of the same jurisdiction — is
        // NOT counted. The old jurisdiction-keyed join would have minted it.
        $this->assertSame(
            0,
            $fixture->table('sim_items')->where('kind', 'count_election')->where('race_id', $electionB)->count(),
            'a second open election of the jurisdiction is not swept in'
        );

        // Idempotent: a re-mint adds nothing (the NOT EXISTS guard holds).
        $again = $fixture->affectingStatement($sql, $bindings);
        $this->assertSame(0, $again, 'a re-mint is a no-op');
    }

    public function test_a_scope_that_produced_no_election_mints_nothing(): void
    {
        if (! $this->usable) {
            $this->markTestSkipped('Fixture not usable.');
        }

        $fixture = DB::connection(self::CONNECTION);

        $run = (string) Str::uuid();
        $jur = (string) Str::uuid();
        $election = (string) Str::uuid();
        $now = now();

        // An open election with a race exists in the jurisdiction, but the
        // election_scope produced no election (blocked / no board): race_id is
        // null. Nothing is minted — the count would have no target.
        $fixture->table('elections')->insert(['id' => $election, 'jurisdiction_id' => $jur, 'status' => 'scheduled']);
        $fixture->table('election_races')->insert(['id' => (string) Str::uuid(), 'election_id' => $election]);
        $fixture->table('sim_items')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => $run,
            'kind' => 'election_scope',
            'status' => 'done',
            'jurisdiction_id' => $jur,
            'race_id' => null,
            'adm_level' => 2,
            'unit_key' => $jur,
            'position' => 0,
            'est_cost' => 0,
            'metrics' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sql = SimPumpCommand::countingMintSql();
        $minted = $fixture->affectingStatement($sql, array_fill(0, substr_count($sql, '?'), $run));

        $this->assertSame(0, $minted, 'a scope with no produced election mints no counting work');
    }

    private function assertMatchesFixtureName(string $name): void
    {
        $this->assertMatchesRegularExpression('/\Acga_siminst_[0-9]{8}_[a-f0-9]{16}\z/', $name, 'fixture name must be strict');
    }
}
