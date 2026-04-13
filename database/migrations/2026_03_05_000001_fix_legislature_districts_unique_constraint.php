<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix: Replace the broad unique constraint on (legislature_id, jurisdiction_id, district_number)
 * with a PARTIAL unique index that excludes soft-deleted rows (WHERE deleted_at IS NULL).
 *
 * Root cause of SQLSTATE[23505]:
 *   The original UNIQUE constraint had no partial exclusion. Soft-deleted rows continued
 *   to occupy their unique key slots, so a second auto-composite run or a manual district
 *   creation would fail when PostgreSQL found the soft-deleted row already holding
 *   district_number=1 for the same (legislature_id, jurisdiction_id) pair.
 *
 * Fix:
 *   1. Hard-delete all soft-deleted legislature_districts rows (and their junction rows)
 *      to remove phantom unique key conflicts from previous runs.
 *   2. Drop the old full-table unique constraint.
 *   3. Make jurisdiction_id nullable (ETL-created root districts have jurisdiction_id = NULL).
 *   4. Create a partial unique index: WHERE deleted_at IS NULL.
 *      This allows the same (legislature_id, jurisdiction_id, district_number) to be reused
 *      after a soft-delete, which is the correct behavior for resettable auto-composite districts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Purge all soft-deleted rows so they can't violate the new constraint either.
        // The junction table uses a hard FK (no deleted_at), so delete it first.
        DB::statement("
            DELETE FROM legislature_district_jurisdictions
            WHERE district_id IN (
                SELECT id FROM legislature_districts WHERE deleted_at IS NOT NULL
            )
        ");
        DB::statement("
            DELETE FROM legislature_districts WHERE deleted_at IS NOT NULL
        ");

        // Step 2: Drop the old broad unique constraint (created by Laravel's ->unique([...]))
        // The generated index name matches the Laravel convention for composite uniques.
        DB::statement("
            ALTER TABLE legislature_districts
            DROP CONSTRAINT IF EXISTS legislature_districts_legislature_id_jurisdiction_id_district_number_unique
        ");

        // Step 3: Make jurisdiction_id nullable — ETL-created root-scope districts (via
        // district_skater.py) set jurisdiction_id = NULL to indicate "root composite".
        Schema::table('legislature_districts', function (Blueprint $table) {
            $table->uuid('jurisdiction_id')->nullable()->change();
        });

        // Step 4: Create the partial unique index — only active (non-deleted) rows are constrained.
        // NULL jurisdiction_id is naturally excluded from uniqueness checks in PostgreSQL
        // (each NULL is considered distinct), which is correct for root ETL districts.
        DB::statement("
            CREATE UNIQUE INDEX legislature_districts_live_unique
            ON legislature_districts (legislature_id, jurisdiction_id, district_number)
            WHERE deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS legislature_districts_live_unique");

        Schema::table('legislature_districts', function (Blueprint $table) {
            $table->uuid('jurisdiction_id')->nullable(false)->change();
            $table->unique(['legislature_id', 'jurisdiction_id', 'district_number']);
        });
    }
};
