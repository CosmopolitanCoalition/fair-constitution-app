<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('constitutional_settings', function (Blueprint $table) {
            $table->string('legislature_sizing_law')->default('cube_root')->after('legislature_max_seats')
                ->comment('Determines how total legislature size is derived from population. v1 ships with cube_root only; future: square_root|fixed_total|log_linear.');
        });
    }

    public function down(): void
    {
        Schema::table('constitutional_settings', function (Blueprint $table) {
            $table->dropColumn('legislature_sizing_law');
        });
    }
};
