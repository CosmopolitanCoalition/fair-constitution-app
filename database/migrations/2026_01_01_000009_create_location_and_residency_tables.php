<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PRIVATE location data — never exposed to other users
        Schema::create('location_pings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_meters', 8, 2)->nullable();

            // mobile | web | manual
            $table->string('source')->default('mobile');

            $table->timestamp('pinged_at');
            $table->timestamps();

            $table->index('user_id');
            $table->index('pinged_at');
            $table->index(['user_id', 'pinged_at']);
        });

        DB::statement('ALTER TABLE location_pings ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // PostGIS point for spatial containment checks
        DB::statement('ALTER TABLE location_pings ADD COLUMN geom GEOMETRY(POINT, 4326)');
        DB::statement('CREATE INDEX location_pings_geom_idx ON location_pings USING GIST(geom)');

        // Auto-populate geom from lat/lng on insert/update
        DB::statement("
            CREATE OR REPLACE FUNCTION set_location_ping_geom()
            RETURNS TRIGGER AS \$\$
            BEGIN
                NEW.geom = ST_SetSRID(ST_MakePoint(NEW.longitude, NEW.latitude), 4326);
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");
        DB::statement("
            CREATE TRIGGER location_pings_set_geom
            BEFORE INSERT OR UPDATE ON location_pings
            FOR EACH ROW EXECUTE FUNCTION set_location_ping_geom();
        ");

        // Confirmed residency — unlocks voting and candidacy (constitutional absolutes)
        Schema::create('residency_confirmations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->cascadeOnDelete();

            $table->unsignedSmallInteger('days_confirmed');
            $table->timestamp('confirmed_at');

            // Rights are automatic upon confirmation — no other requirements
            $table->boolean('voting_right_active')->default(true);
            $table->boolean('candidacy_right_active')->default(true);

            $table->boolean('is_active')->default(true);
            $table->timestamp('deactivated_at')->nullable();
            $table->string('deactivation_reason')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'jurisdiction_id']);
            $table->index('user_id');
            $table->index('jurisdiction_id');
            $table->index('is_active');
        });

        DB::statement('ALTER TABLE residency_confirmations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS location_pings_set_geom ON location_pings');
        DB::statement('DROP FUNCTION IF EXISTS set_location_ping_geom');
        Schema::dropIfExists('residency_confirmations');
        Schema::dropIfExists('location_pings');
    }
};
