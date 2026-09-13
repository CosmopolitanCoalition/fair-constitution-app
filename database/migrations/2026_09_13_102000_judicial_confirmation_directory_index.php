<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', ['judicial_nominations_court_seek_idx']);
        if ($index !== null && ! $index->indisvalid) DB::statement('DROP INDEX CONCURRENTLY judicial_nominations_court_seek_idx');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS judicial_nominations_court_seek_idx ON judicial_nominations (judiciary_id, id DESC) WHERE deleted_at IS NULL');
        $appointmentIndex = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', ['judicial_nominations_appointment_idx']);
        if ($appointmentIndex !== null && ! $appointmentIndex->indisvalid) DB::statement('DROP INDEX CONCURRENTLY judicial_nominations_appointment_idx');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS judicial_nominations_appointment_idx ON judicial_nominations (appointment_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') DB::statement('DROP INDEX CONCURRENTLY IF EXISTS judicial_nominations_court_seek_idx');
        if (DB::getDriverName() === 'pgsql') DB::statement('DROP INDEX CONCURRENTLY IF EXISTS judicial_nominations_appointment_idx');
    }
};
