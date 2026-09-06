<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE STEP 5 TIMING LEDGER (operator order 2026-09-06). The sim mirror of
 * provision_timings: each sim lane accumulates per-part microseconds in process
 * (its stage's execute, the claim acquisition, the gap between claims) and
 * flushes them here as an increment keyed by run and part, so the numbers
 * survive a lane's exit and the Step 5 page shows where the time goes. Same
 * shape as provision_timings so the page and the flush code stay parallel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sim_timings', function (Blueprint $t) {
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
        Schema::dropIfExists('sim_timings');
    }
};
