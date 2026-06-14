<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F — versioned jurisdiction boundary plans. Mirrors
 * `legislature_district_maps` (planet → jurisdiction_maps → jurisdictions, as
 * legislature → district_maps → districts): a boundary change writes a NEW map
 * version rather than mutating rows, so history survives. Union (a new
 * encompassing map), disintermediation (a reparent map), and border settlement
 * (a changed-boundary map) each open a draft version and activate it on passage.
 *
 * One ACTIVE map per root scope; `jurisdictions.map_id` attaches a row to the
 * map version that placed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jurisdiction_maps', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The subtree root this map versions (e.g. a union, a planet root).
            $table->uuid('root_jurisdiction_id');
            $table->foreign('root_jurisdiction_id')->references('id')->on('jurisdictions')->cascadeOnDelete();

            $table->string('name', 160);
            $table->text('description')->nullable();

            $table->string('status', 20)->default('draft'); // draft | active | archived
            $table->unsignedInteger('version_no')->default(1);

            // What opened this version (union/disintermediation/border process).
            $table->string('origin', 24)->nullable();
            $table->uuid('origin_process_id')->nullable();

            $table->date('effective_start')->nullable();
            $table->date('effective_end')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['root_jurisdiction_id', 'status']);
        });

        DB::statement('ALTER TABLE jurisdiction_maps ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE jurisdiction_maps ADD CONSTRAINT jurisdiction_maps_status_check CHECK (status IN ('draft','active','archived'))");

        // One active map per root scope.
        DB::statement(
            'CREATE UNIQUE INDEX jurisdiction_maps_one_active_per_root '
          ."ON jurisdiction_maps (root_jurisdiction_id) WHERE status = 'active' AND deleted_at IS NULL"
        );

        Schema::table('jurisdictions', function (Blueprint $table) {
            $table->uuid('map_id')->nullable()->comment('Phase F: the jurisdiction_map version that placed this row');
            // Art. VI lifecycle (restoration) + federation/union/disintermediation states.
            $table->string('lifecycle_status', 24)->nullable()
                ->comment('Phase F: self_governing|in_union|intermediary|disintermediated|restoration|…');
            $table->index('map_id');
        });
    }

    public function down(): void
    {
        Schema::table('jurisdictions', function (Blueprint $table) {
            $table->dropIndex(['map_id']);
            $table->dropColumn(['map_id', 'lifecycle_status']);
        });
        Schema::dropIfExists('jurisdiction_maps');
    }
};
