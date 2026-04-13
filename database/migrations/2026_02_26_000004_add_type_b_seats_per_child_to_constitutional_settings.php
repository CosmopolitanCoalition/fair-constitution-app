<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('constitutional_settings', function (Blueprint $table) {
            // Per-child equal-house seat count. Default 5 = constitutional STV minimum.
            // Stored on the PARENT jurisdiction's settings row.
            // Amendable via a valid legislative act (enforced in ConstitutionalValidator later).
            $table->unsignedTinyInteger('type_b_seats_per_child')
                  ->default(5)
                  ->after('legislature_max_seats');
        });
    }

    public function down(): void
    {
        Schema::table('constitutional_settings', function (Blueprint $table) {
            $table->dropColumn('type_b_seats_per_child');
        });
    }
};
