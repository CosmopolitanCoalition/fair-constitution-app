<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Operational metadata only; never a simulation outcome or review item.
        Schema::create('sim_statistics_maintenance', function (Blueprint $table) {
            $table->string('target')->primary();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampTz('next_check_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->uuid('last_attempt_id')->nullable();
            $table->string('reason')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->jsonb('change_baseline')->nullable();
        });
        Schema::create('sim_statistics_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('target');
            $table->uuid('run_id');
            $table->string('phase');
            $table->string('reason');
            $table->string('status');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->jsonb('evidence');
            $table->index(['target', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sim_statistics_attempts');
        Schema::dropIfExists('sim_statistics_maintenance');
    }
};
