<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Article IV — Judiciaries.
 *
 * Default type is appointed (not elected). Minimum 5 judges per race
 * (Article IV §1). Term length 10 years, in lockstep with civil
 * appointments (Article II §9). Conversion to elected type requires
 * legislative supermajority + constituent supermajority.
 *
 * Setup wizard's Step 4 inserts one stub row per legislature in
 * `status='forming'`. The elections engine populates judicial_seats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('judiciaries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->cascadeOnDelete();

            $table->string('court_name', 128)->default('Superior Court');

            // appointed (default per Art IV §1) | elected
            $table->string('type', 16)->default('appointed');

            // Min judges per race per Art IV §1.
            $table->unsignedSmallInteger('min_judges')->default(5);

            // Default 10-year terms, lockstep with civil appointments (Art II §9).
            $table->unsignedSmallInteger('term_years')->default(10);

            // forming | active | dissolved
            $table->string('status', 16)->default('forming');

            // For nested judiciaries inheriting from a higher court.
            $table->uuid('parent_judiciary_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('jurisdiction_id');
            $table->index('status');
        });

        DB::statement('ALTER TABLE judiciaries ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        Schema::table('judiciaries', function (Blueprint $table) {
            $table->foreign('parent_judiciary_id')->references('id')->on('judiciaries')->nullOnDelete();
        });

        DB::statement(
            "ALTER TABLE judiciaries ADD CONSTRAINT judiciaries_min_judges_check "
            . "CHECK (min_judges >= 5)"
        );

        DB::statement(
            "ALTER TABLE judiciaries ADD CONSTRAINT judiciaries_type_check "
            . "CHECK (type IN ('appointed', 'elected'))"
        );

        Schema::create('judicial_seats', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('judiciary_id');
            $table->foreign('judiciary_id')->references('id')->on('judiciaries')->cascadeOnDelete();

            $table->uuid('user_id')->nullable();

            $table->unsignedSmallInteger('seat_number');

            $table->date('term_starts_on')->nullable();
            $table->date('term_ends_on')->nullable();

            // vacant | seated | recused | retired
            $table->string('status', 16)->default('vacant');

            $table->timestamps();
            $table->softDeletes();

            $table->index('judiciary_id');
            $table->index('user_id');
            $table->unique(['judiciary_id', 'seat_number', 'deleted_at'], 'judicial_seats_judiciary_seat_unique');
        });

        DB::statement('ALTER TABLE judicial_seats ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            "ALTER TABLE judicial_seats ADD CONSTRAINT judicial_seats_status_check "
            . "CHECK (status IN ('vacant', 'seated', 'recused', 'retired'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('judicial_seats');
        Schema::dropIfExists('judiciaries');
    }
};
