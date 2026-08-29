<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A2 (operator order 2026-08-29): sizing resumes from where it stopped.
 * Step markers — a crash costs one step's remainder, never the pass. The
 * 36,826-parent re-walk after a mid-pass worker death is the defect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoscale_runs', function (Blueprint $table) {
            $table->jsonb('sizing_steps')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('autoscale_runs', function (Blueprint $table) {
            $table->dropColumn('sizing_steps');
        });
    }
};
