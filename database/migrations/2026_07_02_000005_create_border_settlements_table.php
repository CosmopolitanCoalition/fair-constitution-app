<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F (Art. V §2) — Border settlement. Two jurisdictions agree a new shared
 * boundary; it is adopted only if a SUPERMAJORITY of the population IN THE
 * AFFECTED AREA agrees (denominator = the affected sub-jurisdictions ONLY, via
 * CivicPopulation::forArea — never the whole of either jurisdiction). On passage
 * the affected residents are re-associated (point-in-polygon sweep) and a new
 * jurisdiction_maps version records the boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('border_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jurisdiction_a_id');
            $table->foreign('jurisdiction_a_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            $table->uuid('jurisdiction_b_id');
            $table->foreign('jurisdiction_b_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            // The affected sub-jurisdictions whose residents form the electorate.
            $table->jsonb('affected_jurisdiction_ids')->default('[]');
            $table->unsignedInteger('affected_population')->default(0);

            // The affected-area population referendum + its outcome.
            $table->uuid('referendum_election_id')->nullable();
            $table->boolean('affected_supermajority_met')->default(false);

            $table->uuid('jurisdiction_map_id')->nullable(); // the new boundary version

            $table->string('status', 16)->default('open'); // open | adopted | rejected | expired

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
        });

        DB::statement('ALTER TABLE border_settlements ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE border_settlements ADD CONSTRAINT border_settlements_status_check CHECK (status IN ('open','adopted','rejected','expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('border_settlements');
    }
};
