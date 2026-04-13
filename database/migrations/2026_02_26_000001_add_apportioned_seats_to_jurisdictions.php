<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jurisdictions', function (Blueprint $table) {
            // Nullable = not yet seeded. 0 would be meaningful so null distinguishes "uncomputed".
            $table->unsignedSmallInteger('apportioned_seats')->nullable()->after('population_year');
        });
    }

    public function down(): void
    {
        Schema::table('jurisdictions', function (Blueprint $table) {
            $table->dropColumn('apportioned_seats');
        });
    }
};
