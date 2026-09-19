<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sim_worker_leases', function (Blueprint $table) {
            if (! Schema::hasColumn('sim_worker_leases', 'activity')) {
                $table->string('activity', 16)->nullable();
            }
            if (! Schema::hasColumn('sim_worker_leases', 'activity_started_at')) {
                $table->timestampTz('activity_started_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sim_worker_leases', function (Blueprint $table) {
            foreach (['activity', 'activity_started_at'] as $column) {
                if (Schema::hasColumn('sim_worker_leases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
