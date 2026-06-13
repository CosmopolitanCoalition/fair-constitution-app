<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-CASES E-3 (PHASE_E_DESIGN_cases_juries §A) — `panels` + `panel_judges`:
 * the bench SAT to one case. Art. IV §4 — "at least three (3), Odd in number,
 * and scale with the severity… Constitutional Questions of significant
 * importance are heard by the entire court." The odd/≥3 invariant is a DB
 * belt (`size >= 3 AND size % 2 = 1`); the app computes size via the pure
 * PanelSizing::sizeFor. en banc ⇒ the entire seated court.
 *
 * The forward `cases.panel_id` ref (E-1) gets its FK here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panels', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('case_id');
            $table->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();

            $table->uuid('judiciary_id');
            $table->foreign('judiciary_id')->references('id')->on('judiciaries')->restrictOnDelete();

            $table->smallInteger('size');
            $table->boolean('is_en_banc')->default(false);
            $table->string('severity_basis', 20);

            $table->uuid('presiding_judge_seat_id')->nullable();
            $table->foreign('presiding_judge_seat_id')->references('id')->on('judicial_seats')->nullOnDelete();

            // The published random-draw seed (audit-chain sealed, like the jury
            // draw); deterministic re-draw on recusal.
            $table->string('draw_seed', 64)->nullable();

            $table->string('status', 16);

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE panels ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // Art. IV §4 — odd, ≥3, at the DB belt.
        DB::statement(
            'ALTER TABLE panels ADD CONSTRAINT panels_size_odd_check '.
            'CHECK (size >= 3 AND size % 2 = 1)'
        );
        DB::statement(
            'ALTER TABLE panels ADD CONSTRAINT panels_severity_basis_check '.
            "CHECK (severity_basis IN ('minor', 'moderate', 'serious', 'constitutional_major'))"
        );
        DB::statement(
            'ALTER TABLE panels ADD CONSTRAINT panels_status_check '.
            "CHECK (status IN ('drawing', 'screening', 'seated', 'dissolved'))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX panels_case_unique ON panels (case_id) WHERE deleted_at IS NULL'
        );

        // Now the forward cases.panel_id ref gets its FK.
        Schema::table('cases', function (Blueprint $table) {
            $table->foreign('panel_id')->references('id')->on('panels')->nullOnDelete();
        });

        Schema::create('panel_judges', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('panel_id');
            $table->foreign('panel_id')->references('id')->on('panels')->cascadeOnDelete();

            $table->uuid('judicial_seat_id');
            $table->foreign('judicial_seat_id')->references('id')->on('judicial_seats')->restrictOnDelete();

            // Snapshot of the seat holder at draw.
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->boolean('is_presiding')->default(false);

            $table->string('screening_result', 16)->default('pending');
            $table->text('recusal_reason')->nullable();

            $table->string('status', 12);

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE panel_judges ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE panel_judges ADD CONSTRAINT panel_judges_screening_result_check '.
            "CHECK (screening_result IN ('pending', 'cleared', 'recused'))"
        );
        DB::statement(
            'ALTER TABLE panel_judges ADD CONSTRAINT panel_judges_status_check '.
            "CHECK (status IN ('drawn', 'seated', 'recused', 'replaced'))"
        );

        // A seat sits once on a panel.
        DB::statement(
            'CREATE UNIQUE INDEX panel_judges_panel_seat_unique ON panel_judges (panel_id, judicial_seat_id) '.
            'WHERE deleted_at IS NULL'
        );
        // One presiding judge per panel.
        DB::statement(
            'CREATE UNIQUE INDEX panel_judges_one_presiding ON panel_judges (panel_id) '.
            "WHERE is_presiding AND status = 'seated' AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropForeign(['panel_id']);
        });

        Schema::dropIfExists('panel_judges');
        Schema::dropIfExists('panels');
    }
};
