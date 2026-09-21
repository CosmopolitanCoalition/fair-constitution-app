<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const NAME = 'social_memberships_active_space_user_idx';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [self::NAME]);
        if ($index !== null && ! $index->indisvalid) {
            if (DB::selectOne('SELECT pid FROM pg_stat_progress_create_index WHERE index_relid = to_regclass(?)', [self::NAME])) {
                throw new RuntimeException('A room-member index build is still active. Wait before retrying.');
            }
            DB::statement('DROP INDEX CONCURRENTLY '.self::NAME);
        }
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::NAME.' ON social_memberships (space_id, user_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
        }
    }
};
