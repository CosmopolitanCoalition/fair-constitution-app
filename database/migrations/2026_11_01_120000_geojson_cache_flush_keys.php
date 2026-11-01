<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE DURABLE DISTRICT CACHE (operator order 2026-11-01). The district layer
 * (revealed GeoJSON and the color map) joins the durable map cache. Each entry
 * carries the legislature and the scope tag that the Redis tags express, so a
 * district draw or edit clears exactly the entries the Redis flush clears.
 *
 * Additive, REAL-dated, hasColumn-guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geojson_cache', function (Blueprint $t) {
            if (! Schema::hasColumn('geojson_cache', 'legislature_id')) {
                $t->string('legislature_id', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('geojson_cache', 'scope_tag')) {
                $t->string('scope_tag', 200)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('geojson_cache', function (Blueprint $t) {
            foreach (['legislature_id', 'scope_tag'] as $col) {
                if (Schema::hasColumn('geojson_cache', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
