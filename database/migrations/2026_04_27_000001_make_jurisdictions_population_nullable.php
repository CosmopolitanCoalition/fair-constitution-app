<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make jurisdictions.population nullable so "we haven't computed this yet"
 * is distinguishable from "this place has zero people."
 *
 * Original column declaration at:
 *   2026_01_01_000001_create_jurisdictions_table.php:29
 *     $table->unsignedBigInteger('population')->default(0);
 *
 * The NOT NULL + default-0 combo caused the Step 2 wizard's "w/ pop %" cards
 * to always read 100% — every freshly inserted geoBoundaries row arrived with
 * population=0, and SQL `count(population)` treats 0 as "non-NULL = has value."
 *
 * This is a forward-only constraint loosening. The original migration is on
 * the constitutional protected-files list (CLAUDE.md), so we add this
 * migration rather than editing the original. Backfill is conservative:
 * only rows with population=0 AND population_year IS NULL are nulled out.
 * Rows that legitimately measured zero after a WorldPop run keep their
 * population_year set, so they're untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop NOT NULL + default
        DB::statement('ALTER TABLE jurisdictions ALTER COLUMN population DROP DEFAULT');
        DB::statement('ALTER TABLE jurisdictions ALTER COLUMN population DROP NOT NULL');

        // 2. Backfill: convert "unset" placeholder zeros to NULL.
        DB::statement(
            'UPDATE jurisdictions SET population = NULL '
            . 'WHERE population = 0 AND population_year IS NULL'
        );
    }

    public function down(): void
    {
        // Restore the original NOT NULL + default-0 constraint. Existing NULLs
        // get set back to 0 first so the NOT NULL constraint can be re-applied.
        DB::statement('UPDATE jurisdictions SET population = 0 WHERE population IS NULL');
        DB::statement('ALTER TABLE jurisdictions ALTER COLUMN population SET NOT NULL');
        DB::statement('ALTER TABLE jurisdictions ALTER COLUMN population SET DEFAULT 0');
    }
};
