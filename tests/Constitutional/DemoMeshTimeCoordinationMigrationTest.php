<?php

namespace Tests\Constitutional;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * REVERSIBILITY PIN — the demo-mesh time coordination migration
 * (2026_07_29_200000_demo_mesh_time_coordination) is honestly reversible.
 *
 * The desk asked down() be proven LIVE at the cheap moment (empty tables). It
 * was, once, at landing: `migrate:rollback --batch=<this migration's batch>`
 * reverted THIS migration alone — batch-scoped, so lane 15's later-batch
 * education schema was untouched — then `migrate` re-applied it fresh at
 * 2026_07_29_200000. This test is the PERMANENT pin that keeps it reversible:
 * it runs the migration's OWN up() and down() against the live connection inside
 * a rolled-back transaction, so the real DDL executes (Postgres transactional
 * DDL), both directions are asserted, and the rollback leaves the applied schema
 * — and every other lane's — exactly as it was. Re-checked on every suite run.
 */
class DemoMeshTimeCoordinationMigrationTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_demo_mesh_migration';

    public function test_down_removes_every_object_and_up_restores_it_live(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $migration = require base_path(
                'database/migrations/2026_07_29_200000_demo_mesh_time_coordination.php'
            );

            // Precondition: the schema is applied (landed at 2026_07_29_200000).
            $this->assertTrue(Schema::hasColumn('instance_settings', 'time_coordinator_server_id'));
            $this->assertTrue(Schema::hasTable('demo_time_advances'));

            // DOWN — the reversibility proof. Every object it created, it removes.
            $migration->down();

            $this->assertFalse(
                Schema::hasColumn('instance_settings', 'time_coordinator_server_id'),
                'down() drops time_coordinator_server_id'
            );
            $this->assertFalse(
                Schema::hasColumn('instance_settings', 'demo_time_skew_tolerated'),
                'down() drops demo_time_skew_tolerated'
            );
            $this->assertFalse(
                Schema::hasTable('demo_time_advances'),
                'down() drops the demo_time_advances ledger'
            );

            // UP — and it restores exactly what down() removed, with issued_by
            // NULLABLE (the corrected definition; the dev box's transient NOT NULL
            // from the pre-edit sweep self-heals through exactly this path on a
            // migrate:fresh).
            $migration->up();

            $this->assertTrue(Schema::hasColumn('instance_settings', 'time_coordinator_server_id'));
            $this->assertTrue(Schema::hasColumn('instance_settings', 'demo_time_skew_tolerated'));
            $this->assertTrue(Schema::hasTable('demo_time_advances'));

            $issuedByNullable = DB::selectOne(
                "SELECT is_nullable FROM information_schema.columns
                  WHERE table_name = 'demo_time_advances' AND column_name = 'issued_by'"
            );
            $this->assertSame('YES', $issuedByNullable->is_nullable, 'up() makes issued_by nullable');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
