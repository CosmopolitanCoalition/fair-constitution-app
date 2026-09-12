<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'cases_room_directory_idx' => 'ON cases (jurisdiction_id, id) WHERE deleted_at IS NULL',
        'committees_room_directory_idx' => 'ON committees (legislature_id, id) WHERE deleted_at IS NULL',
        'meetings_room_directory_idx' => 'ON committee_meetings (committee_id, scheduled_for DESC, id DESC)',
        'board_seats_room_directory_idx' => "ON board_seats (holder_user_id, id) WHERE status = 'seated' AND deleted_at IS NULL",
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        foreach (self::INDEXES as $name => $definition) {
            $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
            if ($index !== null && ! $index->indisvalid) DB::statement('DROP INDEX CONCURRENTLY '.$name);
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' '.$definition);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        foreach (array_reverse(array_keys(self::INDEXES)) as $name) DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
    }
};
