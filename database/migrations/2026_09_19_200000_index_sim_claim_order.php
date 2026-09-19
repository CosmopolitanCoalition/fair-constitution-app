<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const NAME = 'sim_items_claim_order_idx';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [self::NAME]);
        if ($index !== null && ! $index->indisvalid) {
            if (DB::selectOne('SELECT pid FROM pg_stat_progress_create_index WHERE index_relid = to_regclass(?)', [self::NAME])) {
                throw new RuntimeException('Simulation claim index is still being built by another session. Wait for that build before retrying.');
            }
            DB::statement('DROP INDEX CONCURRENTLY '.self::NAME);
        }

        // Match the entire claim ordering, including ties shared by an ADM level.
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::NAME
            .' ON sim_items (run_id, kind, status, position, id)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
        }
    }
};
