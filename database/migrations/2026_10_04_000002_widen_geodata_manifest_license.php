<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen geodata_dataset_manifests.license from varchar(32) → varchar(255).
 *
 * The original 32 was sized for a single short SPDX code ("CC-BY-4.0", "ODbL"), but a
 * real foundation seed bundles MULTIPLE datasets (geoBoundaries + WorldPop, ODbL for OSM
 * supplements), so the license is a COMBINED expression. The service's own default
 * ("geoBoundaries CC-BY-4.0 + WorldPop CC-BY-4.0", 44 chars) already overran 32 — it was
 * never caught because no real seed had ever been published until the Box B campaign's
 * first donor publish, which failed on this column. License strings are short free text;
 * 255 is ample and conventional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geodata_dataset_manifests', function (Blueprint $table) {
            $table->string('license', 255)->change();
        });
    }

    public function down(): void
    {
        // Best-effort narrowing; a row whose license exceeds 32 chars would block this.
        Schema::table('geodata_dataset_manifests', function (Blueprint $table) {
            $table->string('license', 32)->change();
        });
    }
};
