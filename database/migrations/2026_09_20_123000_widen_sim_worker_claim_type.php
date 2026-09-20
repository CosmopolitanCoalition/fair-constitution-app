<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Match sim_items.kind on existing installations as well as fresh ones.
        // ALTER TYPE preserves NULLs, defaults and existing lease contents.
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement("SET LOCAL statement_timeout = '30s'");
                DB::statement('ALTER TABLE sim_worker_leases ALTER COLUMN claim_type TYPE varchar(24)');
            } else {
                Schema::table('sim_worker_leases', fn (Blueprint $table) => $table->string('claim_type', 24)->nullable()->change());
            }
        });
    }

    public function down(): void
    {
        // Keeping the wider reporting field is compatible with older workers.
        // Never truncate a live claim or restore the known 17-character failure.
    }
};
