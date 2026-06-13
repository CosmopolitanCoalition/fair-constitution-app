<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-5 (PHASE_D_DESIGN_executive §A) — department rules + reports.
 *
 *  - department_rules (F-BOG-001): versioned rules citing an enabling
 *    instrument. "Rules implement, they cannot exceed" is enforced
 *    STRUCTURALLY at filing (citation existence + status + scope);
 *    semantic excess is Phase E judicial-review territory.
 *    `expires_with_enabling=true` (emergency-enabled rules): the
 *    EmergencyPowerService expiry/struck hook flips dependent rules to
 *    `expired` — the CLK-03 cascade.
 *  - department_reports (F-BOG-002): cadence is CHARTER DATA, not a
 *    constitutional clock — plain due_on + nightly sweep (justified
 *    deferral from clock_timers; the 21-clock registry is a
 *    constitutional artifact). On reaching `operating` the first
 *    periodic row seeds at charter date + reporting_interval_months; on
 *    filing, the next row is created. No soft deletes on reports — the
 *    filing record is the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── department_rules ────────────────────────────────────────────────
        Schema::create('department_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('department_id');
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();

            // {DEPT}-R-YYYY-NN
            $table->string('rule_code', 32);
            $table->string('name');
            $table->text('text');

            $table->string('enabling_type', 20);
            $table->uuid('enabling_id');

            $table->boolean('expires_with_enabling')->default(false);

            $table->smallInteger('version_no')->default(1);

            // Self-FK added post-create (Postgres needs the PK in place).
            $table->uuid('supersedes_rule_id')->nullable();

            $table->uuid('filed_by_seat_id');
            $table->foreign('filed_by_seat_id')->references('id')->on('board_seats')->restrictOnDelete();

            $table->uuid('record_id')->nullable();

            $table->string('status', 12)->default('in_force');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['department_id', 'status']);
            $table->index(['enabling_type', 'enabling_id']);
        });

        DB::statement('ALTER TABLE department_rules ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        Schema::table('department_rules', function (Blueprint $table) {
            $table->foreign('supersedes_rule_id')->references('id')->on('department_rules')->nullOnDelete();
        });

        DB::statement(
            "ALTER TABLE department_rules ADD CONSTRAINT department_rules_enabling_type_check " .
            "CHECK (enabling_type IN ('law', 'emergency_power', 'charter'))"
        );
        DB::statement(
            "ALTER TABLE department_rules ADD CONSTRAINT department_rules_status_check " .
            "CHECK (status IN ('draft', 'in_force', 'superseded', 'expired'))"
        );

        // ── department_reports ──────────────────────────────────────────────
        Schema::create('department_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('department_id');
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();

            $table->string('kind', 8)->default('periodic');
            $table->string('period_label')->nullable();

            $table->date('due_on');
            $table->timestampTz('filed_at')->nullable();

            $table->uuid('filed_by_seat_id')->nullable();
            $table->foreign('filed_by_seat_id')->references('id')->on('board_seats')->nullOnDelete();

            $table->jsonb('recipients')->default('["executive","legislature"]');

            $table->uuid('record_id')->nullable();

            $table->string('status', 8)->default('due');

            $table->timestampsTz();

            $table->index(['department_id', 'status']);
            $table->index('due_on');
        });

        DB::statement('ALTER TABLE department_reports ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE department_reports ADD CONSTRAINT department_reports_kind_check " .
            "CHECK (kind IN ('periodic', 'special'))"
        );
        DB::statement(
            "ALTER TABLE department_reports ADD CONSTRAINT department_reports_status_check " .
            "CHECK (status IN ('due', 'filed', 'overdue'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('department_reports');
        Schema::dropIfExists('department_rules');
    }
};
