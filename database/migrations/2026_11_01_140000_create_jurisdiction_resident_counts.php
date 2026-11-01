<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE RESIDENT COUNTER (operator order 2026-11-01). Counting the active residency
 * confirmations of a large place reads millions of index entries (108 million rows
 * on the demo, minutes on a spinning disk). A page that only SHOWS the figure reads
 * this row instead. `residents:recount` refreshes it in bounded, committed chunks.
 * A vote threshold, a petition snapshot or an activation still counts exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jurisdiction_resident_counts')) {
            return;
        }

        Schema::create('jurisdiction_resident_counts', function (Blueprint $t) {
            $t->uuid('jurisdiction_id')->primary();
            $t->unsignedBigInteger('active_residents')->default(0);
            $t->timestampTz('counted_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jurisdiction_resident_counts');
    }
};
