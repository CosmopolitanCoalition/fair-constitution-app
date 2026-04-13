<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legislature_district_maps', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('legislature_id');
            $table->foreign('legislature_id')
                  ->references('id')->on('legislatures')
                  ->onDelete('cascade');

            // Human-readable name for this apportionment plan
            $table->string('name', 120);
            $table->text('description')->nullable();

            // Lifecycle status: draft | active | archived
            // Only one 'active' map per legislature at a time (enforced at app layer)
            $table->string('status', 20)->default('draft');

            // When this apportionment officially takes effect / expires.
            // Null = not yet scheduled / indefinite.
            $table->date('effective_start')->nullable();
            $table->date('effective_end')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('legislature_id');
            $table->index(['legislature_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legislature_district_maps');
    }
};
