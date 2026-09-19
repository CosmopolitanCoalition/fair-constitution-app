<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const NAME = 'legislature_members_active_election_idx';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [self::NAME]);
        if ($index !== null && ! $index->indisvalid) {
            if (DB::selectOne('SELECT pid FROM pg_stat_progress_create_index WHERE index_relid = to_regclass(?)', [self::NAME])) {
                throw new RuntimeException('Active election member index is still being built by another session. Wait for that build before retrying.');
            }
            DB::statement('DROP INDEX CONCURRENTLY '.self::NAME);
        }

        // Match SeatingStage's per-election count of current, non-deleted members.
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::NAME
            ." ON legislature_members (election_id) WHERE deleted_at IS NULL AND status IN ('elected', 'seated')");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
        }
    }
};
