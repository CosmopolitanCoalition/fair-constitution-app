<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worker-lease claim visibility (operator ask 2026-07-19): the dashboard's
 * "Sweeping now" list only shows scopes mid-execution — fast sweeps blink
 * through it and the ten workers look idle. Each lease now carries WHAT its
 * worker is holding right now, so the dashboard can render one honest line
 * per worker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoscale_worker_leases', function (Blueprint $table) {
            $table->string('claim_type', 16)->nullable();
            $table->string('claim_label', 160)->nullable();
            $table->timestampTz('claim_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('autoscale_worker_leases', function (Blueprint $table) {
            $table->dropColumn(['claim_type', 'claim_label', 'claim_started_at']);
        });
    }
};
