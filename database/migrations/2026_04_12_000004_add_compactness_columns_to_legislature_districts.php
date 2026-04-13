<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Replaces Polsby-Popper with two better compactness metrics for admin-unit districting:
 *
 *  convex_hull_ratio  — ST_Area(union) / ST_Area(ST_ConvexHull(union))
 *                       1.0 = perfectly convex; lower = more concave/irregular.
 *                       Not affected by coastlines; measures overall shape quality.
 *
 *  centroid_spread    — mean distance from each member centroid to district centroid,
 *                       normalised by sqrt(district_area / π) (the equivalent-circle radius).
 *                       0.0 = all members at same point; higher = more dispersed.
 *                       Answers: "are the admin units geographically clustered?"
 *
 * polsby_popper is kept for schema backwards-compatibility but nulled out —
 * the new metrics supersede it and it is no longer computed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legislature_districts', function (Blueprint $table) {
            $table->decimal('convex_hull_ratio', 8, 6)->nullable()->after('num_geom_parts');
            $table->decimal('centroid_spread',   8, 6)->nullable()->after('convex_hull_ratio');
        });

        // Null out old PP values — they used the wrong algorithm (simplify-before-union)
        // and are being replaced.  New values are written by recomputeDistrict() on
        // next create/update, or via the backfill command.
        DB::table('legislature_districts')
            ->whereNotNull('polsby_popper')
            ->update(['polsby_popper' => null]);
    }

    public function down(): void
    {
        Schema::table('legislature_districts', function (Blueprint $table) {
            $table->dropColumn(['convex_hull_ratio', 'centroid_spread']);
        });
    }
};
