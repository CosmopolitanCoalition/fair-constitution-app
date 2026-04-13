<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legislature_districts', function (Blueprint $table) {
            // 0–3: greedy 4-color graph coloring — adjacent districts never share a color
            $table->unsignedTinyInteger('color_index')->default(0)->after('floor_override');
        });
    }

    public function down(): void
    {
        Schema::table('legislature_districts', function (Blueprint $table) {
            $table->dropColumn('color_index');
        });
    }
};
