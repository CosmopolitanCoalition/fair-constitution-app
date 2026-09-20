<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vacancies', function (Blueprint $table) {
            // election_races + ordinal represents a seat that never had a member.
            $table->unsignedInteger('unfilled_seat_no')->nullable();
            $table->unsignedInteger('replacement_seat_no')->nullable();
            $table->unique(['seat_type', 'seat_id', 'unfilled_seat_no'], 'vacancies_unfilled_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('vacancies', function (Blueprint $table) {
            $table->dropUnique('vacancies_unfilled_slot_unique');
            $table->dropColumn(['unfilled_seat_no', 'replacement_seat_no']);
        });
    }
};
