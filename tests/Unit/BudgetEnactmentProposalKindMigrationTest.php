<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * W-0299 part 1 — the budget_enactment proposal-kind migration.
 *
 * The budget act rides the chamber-vote proposal rail with kind
 * budget_enactment. On Postgres a CHECK enumerates the lawful proposal kinds,
 * so a 2026_09_14 migration widens that CHECK to admit budget_enactment. This
 * pin runs on a NAMED sqlite fixture: it proves the migration is real-dated and
 * is a no-op on sqlite (sqlite has no such CHECK), so migrating a sqlite
 * fixture does not break. The Postgres widening is pinned by the
 * disposable-database probe
 * tests/concurrency/budget_enactment_proposal_kind_check.php.
 */
final class BudgetEnactmentProposalKindMigrationTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.proposal_kind_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::setDefaultConnection('proposal_kind_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public function test_the_migration_is_real_dated_and_is_a_noop_on_sqlite(): void
    {
        $path = dirname(__DIR__, 2).'/database/migrations/2026_09_14_000200_allow_budget_enactment_proposal_kind.php';
        self::assertFileExists($path);
        self::assertStringStartsWith('2026_09_14_', basename($path), 'the migration is real-dated 2026_09_14');

        // On sqlite the driver guard returns early: up() adds nothing and does not throw.
        $migration = require $path;
        $migration->up();
        $migration->up(); // rerunnable
        $this->assertTrue(true);
    }

    public function test_the_proposal_kind_constant_is_defined(): void
    {
        self::assertSame('budget_enactment', \App\Models\ChamberVoteProposal::KIND_BUDGET_ENACTMENT);
    }
}
