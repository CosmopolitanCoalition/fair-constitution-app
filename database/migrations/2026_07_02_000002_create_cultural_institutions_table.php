<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F (F-LEG-028, Art. V §2) — Cultural Institutions of State. Recognized by
 * a chamber supermajority vote; a recognized institution has NO legislative,
 * executive, or judicial powers — it is an honour on the public record, nothing
 * more (the schema carries no powers columns by construction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cultural_institutions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->cascadeOnDelete();

            $table->uuid('legislature_id')->nullable();
            $table->foreign('legislature_id')->references('id')->on('legislatures')->nullOnDelete();

            $table->string('name', 200);
            $table->text('description')->nullable();

            // The recognizing supermajority chamber vote.
            $table->uuid('recognition_vote_id')->nullable();

            $table->string('status', 16)->default('recognized'); // recognized | dissolved
            $table->uuid('record_id')->nullable(); // public_records publication

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('jurisdiction_id');
        });

        DB::statement('ALTER TABLE cultural_institutions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE cultural_institutions ADD CONSTRAINT cultural_institutions_status_check CHECK (status IN ('recognized','dissolved'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('cultural_institutions');
    }
};
