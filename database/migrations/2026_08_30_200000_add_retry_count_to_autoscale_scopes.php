<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRANSIENT FAILURES SELF-REQUEUE (operator order 2026-08-30): a scope
 * that dies on infrastructure weather — a Redis reload, a dropped
 * connection — throws itself back on the pile with a bounded retry
 * count instead of lying failed until a human clicks. Three tries,
 * then it fails for real. Canada lost 30 seats to a ten-second Redis
 * reload; this is the bound that keeps the speed through blips.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoscale_scopes', function (Blueprint $table) {
            $table->integer('retry_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('autoscale_scopes', function (Blueprint $table) {
            $table->dropColumn('retry_count');
        });
    }
};
