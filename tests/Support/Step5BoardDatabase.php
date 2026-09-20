<?php

namespace Tests\Support;

use App\Models\Board;
use App\Models\Organization;
use App\Models\SimRun;
use Illuminate\Support\Facades\DB;

/** Explicit opt-in, nonce database, raw AND Eloquent routing checked before DDL. */
trait Step5BoardDatabase
{
    private ?string $fixture = null;

    private string $originalConnection;

    private function openBoardFixture(): void
    {
        if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') {
            $this->markTestSkipped('Opt in to disposable Step 5 board/statistics tests.');
        }
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.board_admin' => array_replace(config('database.connections.pgsql'),
            ['database' => 'postgres', 'name' => 'board_admin', 'url' => null])]);
        $admin = DB::connection('board_admin');
        $this->assertSame('postgres', $admin->selectOne('SELECT current_database() AS name')->name);
        $this->fixture = 'sim_board_test_'.bin2hex(random_bytes(8));
        $admin->statement('CREATE DATABASE '.$this->fixture.' TEMPLATE template0');
        config(['database.connections.board_test' => array_replace($admin->getConfig(),
            ['database' => $this->fixture, 'name' => 'board_test', 'url' => null])]);
        DB::setDefaultConnection('board_test');
        $this->assertSame($this->fixture, DB::selectOne('SELECT current_database() AS name')->name);
        foreach ([new Board, new Organization, new SimRun] as $model) {
            $this->assertSame('board_test', $model->getConnection()->getName());
            $this->assertSame($this->fixture, $model->getConnection()->selectOne('SELECT current_database() AS name')->name);
        }
        DB::statement("SET statement_timeout = '15s'");
        DB::statement('CREATE TABLE organizations (id uuid PRIMARY KEY, jurisdiction_id uuid, type text,
            is_active boolean DEFAULT true, board_id uuid, deleted_at timestamptz)');
        DB::statement('CREATE INDEX organizations_jurisdiction_id_index ON organizations(jurisdiction_id)');
        // Disable automatic analysis ONLY inside this nonce fixture to reproduce
        // the reported stale distribution reliably. Production settings stay intact.
        DB::statement('CREATE TABLE boards (id uuid PRIMARY KEY, boardable_type text, boardable_id uuid,
            status text, composition_valid boolean DEFAULT false, created_at timestamptz, updated_at timestamptz,
            deleted_at timestamptz) WITH (autovacuum_enabled = false)');
        DB::statement('CREATE UNIQUE INDEX boards_one_per_body ON boards(boardable_type, boardable_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE TABLE board_seats (id uuid PRIMARY KEY, board_id uuid, seat_class text,
            seat_no integer, holder_user_id uuid, term_id uuid, status text, created_at timestamptz,
            updated_at timestamptz, deleted_at timestamptz)');
        DB::statement('CREATE TABLE terms (id uuid PRIMARY KEY, office_kind text, office_type text, office_id uuid,
            holder_user_id uuid, jurisdiction_id uuid, term_class text, status text, starts_on date, ends_on date,
            created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)');
        DB::statement('CREATE TABLE executives (id uuid PRIMARY KEY, jurisdiction_id uuid, deleted_at timestamptz)');
        DB::statement('CREATE TABLE executive_members (executive_id uuid, user_id uuid, status text, deleted_at timestamptz)');
        DB::statement('CREATE TABLE users (id uuid PRIMARY KEY, email text)');
        DB::statement('CREATE TABLE residency_confirmations (user_id uuid, jurisdiction_id uuid, is_active boolean)');
        DB::statement('CREATE TABLE sim_runs (id uuid PRIMARY KEY, phase text, status text, halt_requested_at timestamptz)');
        DB::statement('CREATE TABLE sim_timings (run_id uuid, part text, count bigint, PRIMARY KEY(run_id, part))');
        DB::statement('CREATE TABLE sim_items (id uuid PRIMARY KEY, run_id uuid, kind text, status text)');
        DB::statement('CREATE INDEX sim_items_claim_order_idx ON sim_items(run_id, kind, status, id)');
    }

    private function closeBoardFixture(): void
    {
        $this->travelBack();
        if ($this->fixture !== null) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::disableQueryLog();
            DB::setDefaultConnection($this->originalConnection);
            DB::purge('board_test');
            if (! preg_match('/^sim_board_test_[a-f0-9]{16}$/D', $this->fixture)) {
                throw new \LogicException('Unexpected fixture database.');
            }
            DB::connection('board_admin')->statement('DROP DATABASE '.$this->fixture.' WITH (FORCE)');
            DB::purge('board_admin');
        }
    }

    private function id(int $n): string
    {
        return sprintf('09000000-0000-4000-8000-%012d', $n);
    }

    private function seedStaleBoards(int $organizations = 2000): void
    {
        DB::statement("INSERT INTO boards(id, boardable_type, boardable_id, status)
            SELECT md5('department-board-' || n)::uuid, 'departments', md5('department-' || n)::uuid, 'active'
            FROM generate_series(1, 20000) n");
        DB::statement("INSERT INTO organizations(id, jurisdiction_id, type)
            SELECT md5('org-' || n)::uuid, md5('place-' || n)::uuid, 'common_good_corp'
            FROM generate_series(1, ?) n", [$organizations]);
        DB::statement('ANALYZE organizations');
        DB::statement('ANALYZE boards');
        DB::statement("INSERT INTO boards(id, boardable_type, boardable_id, status)
            SELECT md5('org-board-' || n)::uuid, 'organizations', md5('org-' || n)::uuid, 'forming'
            FROM generate_series(1, ?) n", [$organizations]);
        $this->flushStatistics();
    }

    private function flushStatistics(): void
    {
        DB::selectOne('SELECT pg_stat_force_next_flush()');
        DB::selectOne('SELECT pg_stat_clear_snapshot()');
    }
}
