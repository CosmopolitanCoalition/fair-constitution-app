<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCOPE-LEVEL HEAVY TIER (2026-07-22, the Earth-swarm crash): the heavy-lane
 * cap keyed on the ITEM's area_tier — but Earth's item is tier 1 (the
 * planetary root row is geometry-less) while its CASCADE fans into
 * continental-scale SUB-scopes. All 10 workers hit those uncapped and
 * postgres crashed into recovery at 10:50Z, four minutes into the swarm.
 * Sub-scope weight is the scope's own geometry, not the item's: the tier now
 * lives on autoscale_scopes (stamped at mint from the scope jurisdiction's
 * bbox), and the claim gate + stale-bound read COALESCE(scope, item, 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoscale_scopes', function (Blueprint $table) {
            $table->smallInteger('area_tier')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('autoscale_scopes', function (Blueprint $table) {
            $table->dropColumn('area_tier');
        });
    }
};
