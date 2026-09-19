<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sim_world_counter_deltas', function (Blueprint $table) {
            $table->uuid('run_id');
            $table->uuid('worker_token');
            $table->primary(['run_id', 'worker_token']);
            $table->foreign('run_id')->references('id')->on('sim_runs')->cascadeOnDelete();
            foreach (['people_founded', 'residencies_founded', 'cohorts', 'chambers_governed',
                'places_zero_population', 'places_too_few_residents'] as $column) {
                $table->bigInteger($column)->default(0);
            }
        });
    }

    public function down(): void
    {
        // Pending deltas must be merged by the running code before removing it.
        if (\Illuminate\Support\Facades\DB::table('sim_world_counter_deltas')->exists()) {
            throw new RuntimeException('Merge pending simulation counters before rolling back this migration.');
        }
        Schema::dropIfExists('sim_world_counter_deltas');
    }
};
