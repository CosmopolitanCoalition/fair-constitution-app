<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE RETRY BOUND THAT SURVIVES (operator catch 2026-08-30, the Bosnia
 * loop): the auto-retry marker lived in the reason text and every fresh
 * failure overwrote it, so the once-only gate reset each cycle and the
 * item spun forever. The counter column is overwritten by nothing: one
 * transient auto-retry per item, then review holds for a human.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoscale_items', function (Blueprint $table) {
            $table->integer('transient_retries')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('autoscale_items', function (Blueprint $table) {
            $table->dropColumn('transient_retries');
        });
    }
};
