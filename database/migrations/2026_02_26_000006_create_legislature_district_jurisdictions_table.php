<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Junction table linking legislature districts to their member jurisdictions.
     *
     * Every district — single-jurisdiction or composite — has at least one row here.
     * Single-jurisdiction districts (mids, giant sub-districts): exactly 1 row.
     * Composite districts (below-floor groupings): N rows (one per member jurisdiction).
     *
     * This design preserves administrative sovereignty: composites are always built
     * from whole, intact jurisdictions. No borders are ever artificially breached.
     */
    public function up(): void
    {
        Schema::create('legislature_district_jurisdictions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('district_id');
            $table->foreign('district_id')
                  ->references('id')->on('legislature_districts')
                  ->onDelete('cascade');

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')
                  ->references('id')->on('jurisdictions')
                  ->onDelete('cascade');

            $table->unique(['district_id', 'jurisdiction_id']);
            $table->index('district_id');
            $table->index('jurisdiction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legislature_district_jurisdictions');
    }
};
