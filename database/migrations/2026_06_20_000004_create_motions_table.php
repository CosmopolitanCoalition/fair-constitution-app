<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-4 (PHASE_C_DESIGN_votes_laws §A) — `motions`, ESM-08.
 *
 * `bill_id` is a plain uuid here; its FK is added by the C-6 bills
 * migration (creation-order cycle break). `amendment_text` (kind
 * 'amendment') becomes a bill_versions row on adoption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('legislature_sessions')->cascadeOnDelete();

            // FK added in the C-6 bills migration.
            $table->uuid('bill_id')->nullable();

            $table->uuid('moved_by_member_id');
            $table->foreign('moved_by_member_id')->references('id')->on('legislature_members')->restrictOnDelete();

            $table->uuid('seconded_by_member_id')->nullable();
            $table->foreign('seconded_by_member_id')->references('id')->on('legislature_members')->nullOnDelete();

            $table->text('text');

            $table->string('kind', 20);
            $table->string('status', 12)->default('submitted');

            $table->uuid('vote_id')->nullable();
            $table->foreign('vote_id')->references('id')->on('chamber_votes')->nullOnDelete();

            // kind 'amendment' → becomes a bill_version on adoption.
            $table->text('amendment_text')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['session_id', 'status']);
            $table->index('bill_id');
        });

        DB::statement('ALTER TABLE motions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("
            ALTER TABLE motions ADD CONSTRAINT motions_kind_check
            CHECK (kind IN ('procedural','referral','direct_to_floor','amendment','table','adjourn','replace_speaker','other'))
        ");
        DB::statement("
            ALTER TABLE motions ADD CONSTRAINT motions_status_check
            CHECK (status IN ('submitted','recognized','debated','voted','adopted','failed','withdrawn'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('motions');
    }
};
