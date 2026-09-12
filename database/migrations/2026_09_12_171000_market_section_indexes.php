<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'marketplace_listings_browse_idx' => "ON marketplace_listings (COALESCE(created_at, '1970-01-01 00:00:00+00'::timestamptz) DESC, id DESC) WHERE status = 'open' AND deleted_at IS NULL",
        'work_postings_browse_idx' => "ON work_postings (COALESCE(created_at, '1970-01-01 00:00:00+00'::timestamptz) DESC, id DESC) WHERE status = 'open' AND deleted_at IS NULL",
        'assistance_requests_browse_idx' => "ON assistance_requests (COALESCE(created_at, '1970-01-01 00:00:00+00'::timestamptz) DESC, id DESC) WHERE status = 'open' AND privacy <> 'private' AND deleted_at IS NULL",
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        // Sequential concurrent builds use the host's existing maintenance budget.
        foreach (self::INDEXES as $name => $definition) {
            $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
            if ($index !== null && ! $index->indisvalid) DB::statement('DROP INDEX CONCURRENTLY '.$name);
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' '.$definition);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        foreach (array_reverse(array_keys(self::INDEXES)) as $name) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }
};
