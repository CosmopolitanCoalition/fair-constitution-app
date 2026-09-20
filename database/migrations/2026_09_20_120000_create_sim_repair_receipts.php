<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sim_repair_receipts', function (Blueprint $table) {
            $table->uuid('source_run_id');
            $table->unsignedInteger('repair_version');
            $table->string('kind', 32);
            $table->uuid('target_id');
            $table->uuid('jurisdiction_id');
            $table->string('status', 16)->default('planned');
            $table->jsonb('result')->nullable();
            $table->timestampsTz();
            $table->primary(['source_run_id', 'repair_version', 'kind', 'target_id'], 'sim_repair_receipts_pk');
            $table->index(['source_run_id', 'repair_version', 'jurisdiction_id'], 'sim_repair_receipts_scope_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Repair receipts are durable recovery history; do not remove them from a repaired world.');
    }
};
