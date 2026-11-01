<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE DURABLE MAP CACHE (operator order 2026-11-01).
 *
 * The map GeoJSON results (children, siblings, self) were cached only in the
 * cache Redis, which starts empty on every restart by design. A rebooted box
 * therefore recomputed every PostGIS map query. Boundary geometry changes only
 * on a map import or a restore, so the computed result is kept here as a second
 * tier and read back after a reboot. Import, restore and the repair command
 * clear this table beside the Redis flush.
 *
 * Additive, REAL-dated, hasTable-guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('geojson_cache')) {
            return;
        }

        Schema::create('geojson_cache', function (Blueprint $t) {
            $t->string('cache_key', 200)->primary();
            $t->text('payload');
            $t->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geojson_cache');
    }
};
