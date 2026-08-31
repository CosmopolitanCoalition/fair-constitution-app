<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE REAPER'S EYES (operator order 2026-08-30): every lane records its
 * postgres backend pid on its lease. Death detection becomes positive —
 * a lane mid-query cannot heartbeat but its backend is present in
 * pg_stat_activity; a killed lane's backend vanishes within seconds.
 * Heartbeat stale AND backend absent = certainly dead, and only then
 * does the pump reap: lease deleted, scope repending, replacement
 * dispatched. No timer ever shoots a live grinder again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoscale_worker_leases', function (Blueprint $table) {
            $table->integer('pg_backend_pid')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('autoscale_worker_leases', function (Blueprint $table) {
            $table->dropColumn('pg_backend_pid');
        });
    }
};
