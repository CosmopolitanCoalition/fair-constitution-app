<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-3 (PHASE_D_DESIGN_executive §A) — `departments` (ESM-17).
 *
 * Created ONLY by an adopted F-LEG-016 Department Creation Act (charter
 * law kind 'charter'); never auto-seeded — Art. II §9 says LEGISLATURES
 * create the five named departments, so the mandatory-five set is a
 * surface checklist + batch-file convenience, never an engine bypass.
 *
 * `worker_count` is the department's denormalized active-worker counter
 * cache — departments hire through the SAME polymorphic worker table as
 * organizations (orgs design D-O2 [COORD-EXEC]); the orgs designer's
 * headcount job maintains both caches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            // Oversight assignment — named in the creation act.
            $table->uuid('executive_id');
            $table->foreign('executive_id')->references('id')->on('executives')->restrictOnDelete();

            $table->string('kind', 20);
            $table->string('name');

            // The F-LEG-016 act (laws.kind = 'charter').
            $table->uuid('charter_law_id');
            $table->foreign('charter_law_id')->references('id')->on('laws')->restrictOnDelete();

            // From the charter; drives the WF-EXE-09 reporting cadence.
            $table->smallInteger('reporting_interval_months')->nullable();

            // Set when the board row is created in the same adoption txn.
            $table->uuid('board_id')->nullable();
            $table->foreign('board_id')->references('id')->on('boards')->nullOnDelete();

            // Headcount counter cache (orgs designer's job writes it).
            $table->integer('worker_count')->default(0);

            $table->string('status', 24)->default('chartered');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['jurisdiction_id', 'status']);
            $table->index('executive_id');
        });

        DB::statement('ALTER TABLE departments ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE departments ADD CONSTRAINT departments_kind_check CHECK (kind IN (" .
            "'chief_executive', 'treasury', 'defense', 'state', 'justice', 'other'))"
        );
        DB::statement(
            "ALTER TABLE departments ADD CONSTRAINT departments_status_check CHECK (status IN (" .
            "'chartered', 'oversight_assigned', 'governors_nominated', 'consented', " .
            "'operating', 'reporting', 'rechartered', 'dissolved'))"
        );
        // One Treasury (etc.) per jurisdiction; 'other' departments repeat.
        DB::statement(
            "CREATE UNIQUE INDEX departments_one_named_kind ON departments (jurisdiction_id, kind) " .
            "WHERE kind <> 'other' AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
