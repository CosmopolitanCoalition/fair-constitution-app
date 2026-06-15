<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase H (H0 substrate) — make district membership POLYMORPHIC.
 *
 * A district member is EITHER a whole jurisdiction (the composite case,
 * unchanged) OR a drawn electoral sub-unit of a childless leaf giant
 * (`district_subdivisions`). The additive `subdivision_id` + an exactly-one
 * CHECK lets a `legislature_districts` row carry either kind with no
 * special-casing elsewhere: every junction reader that joins to `jurisdictions`
 * adds a parallel join to `district_subdivisions` (design §3.2).
 *
 * `legislature_district_jurisdictions` is NOT a protected table — additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legislature_district_jurisdictions', function (Blueprint $table) {
            $table->uuid('subdivision_id')->nullable()->after('jurisdiction_id');
            $table->foreign('subdivision_id')
                  ->references('id')->on('district_subdivisions')
                  ->onDelete('cascade');
            $table->index('subdivision_id');
        });

        // A drawn member has no jurisdiction_id — relax the NOT NULL.
        DB::statement('ALTER TABLE legislature_district_jurisdictions ALTER COLUMN jurisdiction_id DROP NOT NULL');

        // The old plain unique (jurisdiction_id assumed present) is replaced by
        // two partial uniques, one per member kind. Name is the 63-char-truncated
        // constraint Postgres actually created.
        DB::statement(
            'ALTER TABLE legislature_district_jurisdictions '
          .'DROP CONSTRAINT IF EXISTS "legislature_district_jurisdictions_district_id_jurisdiction_id_"'
        );

        // Exactly one member kind populated per row.
        DB::statement(
            'ALTER TABLE legislature_district_jurisdictions ADD CONSTRAINT ldj_member_kind_xor_check '
          .'CHECK ( (jurisdiction_id IS NOT NULL)::int + (subdivision_id IS NOT NULL)::int = 1 )'
        );

        DB::statement(
            'CREATE UNIQUE INDEX ldj_district_jurisdiction_unique '
          .'ON legislature_district_jurisdictions (district_id, jurisdiction_id) WHERE jurisdiction_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX ldj_district_subdivision_unique '
          .'ON legislature_district_jurisdictions (district_id, subdivision_id) WHERE subdivision_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ldj_district_jurisdiction_unique');
        DB::statement('DROP INDEX IF EXISTS ldj_district_subdivision_unique');
        DB::statement('ALTER TABLE legislature_district_jurisdictions DROP CONSTRAINT IF EXISTS ldj_member_kind_xor_check');

        Schema::table('legislature_district_jurisdictions', function (Blueprint $table) {
            $table->dropForeign(['subdivision_id']);
            $table->dropIndex(['subdivision_id']);
            $table->dropColumn('subdivision_id');
        });

        // Restore the original plain unique (jurisdiction_id is required again).
        DB::statement('ALTER TABLE legislature_district_jurisdictions ALTER COLUMN jurisdiction_id SET NOT NULL');
        DB::statement(
            'ALTER TABLE legislature_district_jurisdictions '
          .'ADD CONSTRAINT legislature_district_jurisdictions_district_id_jurisdiction_id_unique '
          .'UNIQUE (district_id, jurisdiction_id)'
        );
    }
};
