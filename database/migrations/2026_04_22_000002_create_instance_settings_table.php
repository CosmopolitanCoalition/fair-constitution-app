<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('instance_name')->default('Unnamed Instance');

            // Cosmic placement — FK to cosmic_addresses world-level row.
            $table->uuid('cosmic_address_id')->nullable();
            $table->foreign('cosmic_address_id')
                ->references('id')
                ->on('cosmic_addresses')
                ->nullOnDelete();

            // Rendering mode. Only `physical_earth` selectable in v1.
            $table->string('map_mode')->default('physical_earth')
                ->comment('physical_earth|multiverse|elsewhere|no_map');

            // Time mode. Real-world clock or accelerated simulation.
            $table->string('time_mode')->default('real')
                ->comment('real|accelerated');
            $table->unsignedInteger('time_scale_seconds_per_year')->nullable()
                ->comment('Used when time_mode=accelerated; null in real mode');

            // Wizard progression state.
            $table->unsignedSmallInteger('setup_step_completed')->default(0);
            $table->timestamp('setup_completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('ALTER TABLE instance_settings ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // Enforce singleton via partial unique index on a constant literal — only
        // one non-deleted row can exist.
        DB::statement(
            'CREATE UNIQUE INDEX instance_settings_singleton_idx '
          . 'ON instance_settings ((1)) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_settings');
    }
};
