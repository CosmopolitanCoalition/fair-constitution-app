<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE LAWFUL-INACTIVE COUNTERS (operator ruling 2026-09-19,
 * sim-roster-vs-real-population = C, "ceiling at real population").
 *
 * The simulation never mints more people than a place has real residents, and
 * zero is zero. Two kinds of place therefore close DONE with no election. They
 * are counted on the run, the same O(1) way as the world counters
 * (2026_09_13_183000), so the Step 5 page shows them without a scan:
 *
 *   places_zero_population     identity items that found a zero population
 *   places_too_few_residents   election items closed because the place has
 *                              fewer real residents than its election needs
 *
 * Additive, REAL-dated, hasColumn-guarded both ways.
 */
return new class extends Migration
{
    /** @var array<int,string> */
    private array $columns = [
        'places_zero_population',
        'places_too_few_residents',
    ];

    public function up(): void
    {
        Schema::table('sim_runs', function (Blueprint $t) {
            foreach ($this->columns as $col) {
                if (! Schema::hasColumn('sim_runs', $col)) {
                    $t->unsignedBigInteger($col)->default(0);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('sim_runs', function (Blueprint $t) {
            foreach ($this->columns as $col) {
                if (Schema::hasColumn('sim_runs', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
