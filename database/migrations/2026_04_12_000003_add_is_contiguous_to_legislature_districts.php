<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add is_contiguous to legislature_districts.
     *
     * Replaces the unreliable num_geom_parts > 1 contiguity heuristic with a
     * proper boolean flag computed via BFS graph connectivity on member jurisdiction
     * adjacency (ST_Intersects).  Unlike num_geom_parts, this correctly ignores
     * islands *within* individual member jurisdictions (e.g. Michigan's Upper
     * Peninsula, Massachusetts' offshore islands) and only flags districts where
     * a member jurisdiction is genuinely unreachable from the others.
     *
     * NULL = not yet computed (backfill pending)
     * TRUE  = all members form one connected set via shared borders
     * FALSE = at least one member is spatially isolated from the rest
     *
     * Populated by:
     *   - recomputeDistrict()          — on every PHP create/update
     *   - districts:backfill-stats     — one-time backfill for existing districts
     */
    public function up(): void
    {
        Schema::table('legislature_districts', function (Blueprint $table) {
            $table->boolean('is_contiguous')->nullable()->after('num_geom_parts');
        });
    }

    public function down(): void
    {
        Schema::table('legislature_districts', function (Blueprint $table) {
            $table->dropColumn('is_contiguous');
        });
    }
};
