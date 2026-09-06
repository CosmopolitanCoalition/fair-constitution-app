<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE STEP 4 TIMING LEDGER (operator order 2026-09-06). Step 3 timed how long
 * each part of a claim took and how long a lane sat between claims, and those
 * timings revealed the points to target for speed. Step 4 needs the same. Each
 * lane accumulates per-part microseconds in process and flushes them here as an
 * increment (count, total, max) keyed by run and part, so completed lanes'
 * timings survive their exit and the page can aggregate them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provision_timings', function (Blueprint $t) {
            $t->uuid('run_id');
            $t->string('part', 48);
            $t->bigInteger('count')->default(0);
            $t->bigInteger('total_us')->default(0);
            $t->bigInteger('max_us')->default(0);
            $t->timestampTz('updated_at')->nullable();
            $t->primary(['run_id', 'part']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provision_timings');
    }
};
