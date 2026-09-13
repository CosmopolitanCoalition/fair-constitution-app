<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'users_governor_public_name_idx' => 'ON users (lower(display_name) COLLATE "C", id) WHERE deleted_at IS NULL AND display_name IS NOT NULL AND display_name <> \'\'',
        'profiles_governor_public_name_idx' => 'ON social_profiles (lower(display_name) COLLATE "C", user_id) WHERE deleted_at IS NULL AND visibility = \'public\' AND display_name IS NOT NULL AND display_name <> \'\'',
        'profiles_governor_public_handle_idx' => 'ON social_profiles (lower(\'@\' || handle) COLLATE "C", user_id) WHERE deleted_at IS NULL AND visibility = \'public\' AND handle IS NOT NULL AND handle <> \'\'',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::INDEXES as $name => $definition) {
            $index = DB::selectOne('SELECT i.indisvalid FROM pg_index i WHERE i.indexrelid = to_regclass(?)', [$name]);
            if ($index !== null && ! $index->indisvalid) {
                DB::statement('DROP INDEX CONCURRENTLY '.$name);
            }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' '.$definition);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }
};
