<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE SIMULATION DIAL (operator order 2026-09-19). The simulate choice moved
 * from map acceptance (end of Step 2) to the lock of Step 4, and two controls
 * joined it. All three are stored at the Step 4 lock and read by the Step 5
 * start (App\Support\SimDial is the single owner of the defaults and bounds).
 *
 *   sim_sample_pct    percent of each leaf population minted as people.
 *                     Default 0.1 (one resident per thousand).
 *   sim_roster_floor  where the dial leaves a place with too few people to
 *                     contest its election, mint the shortfall for that place.
 *                     Off: that place files to review.
 *
 * Additive-only, REAL-dated (post-flatten law).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('instance_settings', 'sim_sample_pct')) {
                $table->decimal('sim_sample_pct', 7, 4)->default(0.1);
            }
            if (! Schema::hasColumn('instance_settings', 'sim_roster_floor')) {
                $table->boolean('sim_roster_floor')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['sim_sample_pct', 'sim_roster_floor']);
        });
    }
};
