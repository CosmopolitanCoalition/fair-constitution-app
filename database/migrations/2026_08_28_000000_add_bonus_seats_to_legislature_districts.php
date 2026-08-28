<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// THE LEGISLATURE CEILING EXCEPTION (operator ruling 2026-08-28): a forced
// floor exception whose lawful landing is 1 or 0 seats receives bonus seats
// added to the legislature itself, raising the district to exactly 2 so the
// runner-up is represented too. The bonus rides the district row; every
// exactness identity compares (seats - bonus_seats) against the budget.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legislature_districts', function (Blueprint $table) {
            $table->smallInteger('bonus_seats')->notNull()->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('legislature_districts', function (Blueprint $table) {
            $table->dropColumn('bonus_seats');
        });
    }
};
