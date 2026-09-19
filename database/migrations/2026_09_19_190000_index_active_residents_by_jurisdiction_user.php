<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // The demo may be running. Concurrent builds permit ordinary reads/writes
    // but still consume I/O and can wait for transactions; coordinate one build.
    public $withinTransaction = false;

    private const NAME = 'residency_active_jurisdiction_user_idx';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [self::NAME]);
        if ($index !== null && ! $index->indisvalid) {
            // A cancelled concurrent build leaves an INVALID index. IF NOT
            // EXISTS alone would silently keep it and record a false success.
            if (DB::selectOne('SELECT pid FROM pg_stat_progress_create_index WHERE index_relid = to_regclass(?)', [self::NAME])) {
                throw new RuntimeException('Resident index is still being built by another session. Wait for that build before retrying.');
            }
            DB::statement('DROP INDEX CONCURRENTLY '.self::NAME);
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::NAME
            .' ON residency_confirmations (jurisdiction_id, user_id) WHERE is_active');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
        }
    }
};
