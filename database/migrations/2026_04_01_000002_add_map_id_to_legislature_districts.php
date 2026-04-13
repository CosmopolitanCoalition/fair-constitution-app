<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── Step 1: Add map_id column ─────────────────────────────────────────
        Schema::table('legislature_districts', function (Blueprint $table) {
            $table->uuid('map_id')->nullable()->after('legislature_id');
            $table->foreign('map_id')
                  ->references('id')->on('legislature_district_maps')
                  ->nullOnDelete();
            $table->index('map_id');
        });

        // ── Step 2: Update unique constraint to include map_id ────────────────
        // Drop the old partial unique index (created in fix_legislature_districts_unique_constraint)
        DB::statement('DROP INDEX IF EXISTS legislature_districts_live_unique');

        // New constraint scoped per map — two maps can have the same district_number
        // for the same jurisdiction without conflicting.
        DB::statement("
            CREATE UNIQUE INDEX legislature_districts_live_unique
            ON legislature_districts (legislature_id, jurisdiction_id, district_number, map_id)
            WHERE deleted_at IS NULL
        ");

        // ── Step 3: Data migration — wrap existing districts in a named map ───
        // Find all distinct legislature_ids that currently have districts.
        $legislatureIds = DB::table('legislature_districts')
            ->whereNull('map_id')
            ->distinct()
            ->pluck('legislature_id');

        foreach ($legislatureIds as $legId) {
            // Look up the legislature name for a human-readable map label
            $leg = DB::table('legislatures')->where('id', $legId)->first();
            $rootJurisdiction = $leg
                ? DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->first()
                : null;
            $label = ($rootJurisdiction ? $rootJurisdiction->name . ' — ' : '') . 'Auto-Seeded v1';

            $mapId = (string) \Illuminate\Support\Str::uuid();

            DB::table('legislature_district_maps')->insert([
                'id'             => $mapId,
                'legislature_id' => $legId,
                'name'           => $label,
                'status'         => 'draft',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            // Assign all existing districts (including soft-deleted) to this map
            DB::table('legislature_districts')
                ->where('legislature_id', $legId)
                ->whereNull('map_id')
                ->update(['map_id' => $mapId]);
        }
    }

    public function down(): void
    {
        // Remove the new partial unique index
        DB::statement('DROP INDEX IF EXISTS legislature_districts_live_unique');

        // Restore the original partial unique index (without map_id)
        DB::statement("
            CREATE UNIQUE INDEX legislature_districts_live_unique
            ON legislature_districts (legislature_id, jurisdiction_id, district_number)
            WHERE deleted_at IS NULL
        ");

        Schema::table('legislature_districts', function (Blueprint $table) {
            $table->dropForeign(['map_id']);
            $table->dropIndex(['map_id']);
            $table->dropColumn('map_id');
        });
    }
};
