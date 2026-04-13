<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add Phase 1–4 workflow columns to legislature_districts
        Schema::table('legislature_districts', function (Blueprint $table) {
            // Tracks progress through the 4-phase districting algorithm:
            //   phase1_complete → composite | mid | giant_pending → locked | split
            $table->string('status', 30)->default('phase1_complete')->after('actual_population');

            // Fractional seat entitlement from Phase 1: child.population / quota
            // Written by PHP seeder; read by Python for Phase 2 compositing
            $table->decimal('fractional_seats', 10, 6)->nullable()->after('seats');

            // Constitutional minimum guarantee — district receives exactly 5 seats
            // from Webster regardless of population (sparse/isolated communities)
            $table->boolean('floor_override')->default(false)->after('fractional_seats');
        });

        // Make jurisdiction_id nullable so composite districts (which span multiple
        // jurisdictions) can exist without a single primary FK.
        // Composite membership is tracked in legislature_district_jurisdictions.
        DB::statement('ALTER TABLE legislature_districts ALTER COLUMN jurisdiction_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Restore NOT NULL (only safe if no composite rows exist)
        DB::statement('ALTER TABLE legislature_districts ALTER COLUMN jurisdiction_id SET NOT NULL');

        Schema::table('legislature_districts', function (Blueprint $table) {
            $table->dropColumn(['status', 'fractional_seats', 'floor_override']);
        });
    }
};
