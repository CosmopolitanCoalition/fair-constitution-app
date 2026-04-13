<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jurisdictions', function (Blueprint $table) {
            // Drop the single undifferentiated column
            $table->dropColumn('apportioned_seats');

            // Population-proportional seats this jurisdiction holds in its parent's legislature
            // Formula: max(5, round(N × pop / max_sibling_pop))
            $table->unsignedSmallInteger('type_a_apportioned')->nullable()->after('population_year');

            // Equal-house seats this jurisdiction holds in its parent's legislature
            // Default: 5 (constitutional STV minimum). Parent-configurable via constitutional_settings.
            $table->unsignedSmallInteger('type_b_apportioned')->nullable()->after('type_a_apportioned');
        });
    }

    public function down(): void
    {
        Schema::table('jurisdictions', function (Blueprint $table) {
            $table->dropColumn(['type_a_apportioned', 'type_b_apportioned']);
            $table->unsignedSmallInteger('apportioned_seats')->nullable()->after('population_year');
        });
    }
};
