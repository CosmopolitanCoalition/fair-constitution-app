<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jurisdictions', function (Blueprint $table) {
                // UUID primary key — gen_random_uuid() default set at DB level below
            $table->uuid('id')->primary();

            // Identity
            $table->string('name');
            $table->string('slug')->unique()->comment('URL-safe identifier');
            $table->string('iso_code')->nullable()->comment('ISO 3166 code where applicable');

            // Hierarchy
            // adm_level: 0=Earth, 1=National, 2=State/Province, 3=County/Region, 4=Local, 5+=Sub-local
            $table->unsignedTinyInteger('adm_level')->default(4);
            $table->uuid('parent_id')->nullable();
            // Self-referential FK added AFTER table creation — PostgreSQL requires PK to be
            // fully committed before a self-referential FK can reference it

            // Population (from WorldPop ETL)
            $table->unsignedBigInteger('population')->default(0);
            $table->unsignedSmallInteger('population_year')->nullable();

            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_bootstrapping')->default(false)
                ->comment('True during Article VI restoration process');

            // Federation — which server is authoritative for this jurisdiction
            $table->uuid('authoritative_server_id')->nullable()
                ->comment('NULL = this server is authoritative');
            $table->string('authoritative_server_url')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            // Data provenance
            $table->string('source')->default('user_defined')
                ->comment('geoboundaries|osm|user_defined|computed_skater');
            $table->string('geoboundaries_id')->nullable();
            $table->string('osm_relation_id')->nullable();

            // Official languages (JSON array of ISO 639-1 codes)
            $table->json('official_languages')->default('["en"]');

            // Timezone
            $table->string('timezone')->default('UTC');

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('adm_level');
            $table->index('parent_id');
            $table->index('is_active');
            $table->index('authoritative_server_id');
        });

        // Set UUID default at DB level AFTER table creation
        DB::statement('ALTER TABLE jurisdictions ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // Add self-referential FK now that the primary key is fully committed
        Schema::table('jurisdictions', function (Blueprint $table) {
            $table->foreign('parent_id')
                ->references('id')
                ->on('jurisdictions')
                ->nullOnDelete();
        });

        // PostGIS geometry column — must be added raw, Blueprint doesn't support it
        // SRID 4326 = WGS84 (standard GPS coordinate system)
        DB::statement('ALTER TABLE jurisdictions ADD COLUMN geom GEOMETRY(MULTIPOLYGON, 4326)');

        // Spatial index for ST_Contains(), ST_Intersects() queries
        DB::statement('CREATE INDEX jurisdictions_geom_idx ON jurisdictions USING GIST(geom)');

        // Centroid for quick distance calculations
        DB::statement('ALTER TABLE jurisdictions ADD COLUMN centroid GEOMETRY(POINT, 4326)');
        DB::statement('CREATE INDEX jurisdictions_centroid_idx ON jurisdictions USING GIST(centroid)');
    }

    public function down(): void
    {
        Schema::dropIfExists('jurisdictions');
    }
};
