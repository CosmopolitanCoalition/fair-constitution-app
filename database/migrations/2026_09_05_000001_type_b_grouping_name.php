<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a nullable operator-set NAME to Type B groupings so the mapper's map
 * selector can distinguish maps (create / rename / "Copy of …"), matching the
 * Type A district-map naming. Absent a name the display falls back to the
 * status+date derivation. Additive, real-dated (>= 2026-07-05 baseline).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legislature_type_b_groupings', function (Blueprint $table) {
            $table->string('name')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('legislature_type_b_groupings', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
