<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * force_box on a scope (operator order 2026-09-03, the grind shunt). When the
 * grind watchdog terminates a scope's stuck backend, it sets force_box so the
 * scope redraws through the box template instead of parking in review. The
 * flag is also the loop guard: a scope that grinds AGAIN while force_box is
 * already set (the box itself could not finish) parks in review rather than
 * being shunted a second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apportionment_ledger_scopes', function (Blueprint $table) {
            $table->boolean('force_box')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('apportionment_ledger_scopes', function (Blueprint $table) {
            $table->dropColumn('force_box');
        });
    }
};
