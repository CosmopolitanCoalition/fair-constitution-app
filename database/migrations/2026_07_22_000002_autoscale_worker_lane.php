<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TOP-DOWN LANE (operator ruling 2026-07-22): composite districting is
 * proven fast (Earth: the full planetary 223-district cascade in ~2h), so
 * 20% of the worker pool works the queue TOP-DOWN — most complex, highest
 * population first — churning the composite/mixed maps early and surfacing
 * bug classes the bottom-up wall would not reach for days. The lane lives
 * on the worker lease so the pump can maintain each pool's headcount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoscale_worker_leases', function (Blueprint $table) {
            $table->string('lane', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('autoscale_worker_leases', function (Blueprint $table) {
            $table->dropColumn('lane');
        });
    }
};
