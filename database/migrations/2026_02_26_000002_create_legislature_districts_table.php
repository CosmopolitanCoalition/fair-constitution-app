<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legislature_districts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // The parent jurisdiction's legislature this district belongs to
            $table->uuid('legislature_id');
            $table->foreign('legislature_id')
                  ->references('id')->on('legislatures')
                  ->onDelete('cascade');

            // The child jurisdiction whose geographic area this district is drawn within
            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')
                  ->references('id')->on('jurisdictions')
                  ->onDelete('cascade');

            // 1-based district number, scoped to legislature + jurisdiction
            $table->unsignedSmallInteger('district_number');

            // STV district size — always 5 to 9 (constitutional constraint)
            $table->unsignedSmallInteger('seats');

            // Population targets — set by apportionment algorithm
            $table->unsignedBigInteger('target_population');
            $table->unsignedBigInteger('actual_population')->nullable(); // filled after SKATER

            // Geometry — filled in by DistrictingService (SKATER algorithm)
            // Null until spatial drawing runs
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['legislature_id', 'jurisdiction_id', 'district_number']);
            $table->index('legislature_id');
            $table->index('jurisdiction_id');
        });

        // Add PostGIS geometry column separately (Blueprint doesn't natively support it)
        DB::statement("
            ALTER TABLE legislature_districts
            ADD COLUMN geom geometry(MultiPolygon, 4326) NULL
        ");

        DB::statement("
            CREATE INDEX legislature_districts_geom_idx
            ON legislature_districts USING GIST (geom)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('legislature_districts');
    }
};
