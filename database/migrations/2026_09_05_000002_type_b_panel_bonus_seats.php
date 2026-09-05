<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add per-panel BONUS SEATS to Type B panels (operator ruling 2026-09-05 — the
 * Type A bonus mechanism, here for Type B). With rep_floor = 2, p = floor(bound/2)
 * panels of 2 seats leave a leftover odd seat when the bound is odd; that seat is
 * a bonus granted to ONE panel (raised to 3, and given proportionally more
 * members so representation weight stays uniform). bonus_seats records the excess
 * over rep_floor per panel, so seats − bonus_seats == rep_floor is the identity —
 * the same shape as legislature_districts.bonus_seats for Type A. Defaults 0 so
 * hand-built panels (createPanel) need not set it. Additive, real-dated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legislature_type_b_panels', function (Blueprint $table) {
            $table->smallInteger('bonus_seats')->default(0)->after('seats');
        });
    }

    public function down(): void
    {
        Schema::table('legislature_type_b_panels', function (Blueprint $table) {
            $table->dropColumn('bonus_seats');
        });
    }
};
