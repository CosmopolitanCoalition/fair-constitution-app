<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * W-0299 part 3 — a hard balance floor on economic_accounts.
 *
 * The individual economy has no credit facility. AccountService refuses an
 * overdraft under a row lock, so the app layer already keeps every balance at
 * or above zero. This adds the same guarantee at the storage layer, so even a
 * direct SQL statement cannot drive an account negative.
 *
 * Postgres only. The CHECK is added NOT VALID: every existing balance is
 * already at or above zero, so a table-wide validation scan is not needed, and
 * every new write is checked. Sqlite cannot ALTER TABLE ADD CONSTRAINT, so on
 * sqlite this is a no-op; the app-layer refusal has its own sqlite pin.
 * Rerunnable: the add is skipped when the constraint is already present.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const CONSTRAINT = 'economic_accounts_balance_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = DB::selectOne(
            'SELECT 1 AS present FROM pg_constraint
              WHERE conname = ? AND conrelid = ?::regclass',
            [self::CONSTRAINT, 'economic_accounts']
        );

        if ($present === null) {
            DB::statement('ALTER TABLE economic_accounts ADD CONSTRAINT '.self::CONSTRAINT.' CHECK (balance >= 0) NOT VALID');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE economic_accounts DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
    }
};
