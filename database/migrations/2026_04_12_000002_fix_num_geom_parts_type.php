<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Change num_geom_parts from SMALLINT to INTEGER.
     *
     * SMALLINT max is 32,767. Highly fragmented jurisdictions
     * (e.g. island nations with thousands of separate polygon parts)
     * can exceed this limit when their geometries are unioned via ST_NumGeometries.
     * INTEGER (max ~2.1 billion) is safe for any realistic geometry.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE legislature_districts ALTER COLUMN num_geom_parts TYPE integer');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE legislature_districts ALTER COLUMN num_geom_parts TYPE smallint');
    }
};
