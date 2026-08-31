<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE APPORTIONMENT LEDGER (operator order 2026-08-31): materialization is
 * a property of the POPULATION DATA, not of a run — like adjacency is a
 * property of the geometry. Computed at the ingest tail, once per dataset:
 * every legislature's head, its full stamped scope tree (budgets + walk
 * order), and its pre-draw gate verdict. Runs COPY from the ledger; the
 * whole world's arithmetic defects are known before any run exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apportionment_ledger', function (Blueprint $table) {
            $table->uuid('legislature_id')->primary();
            $table->uuid('jurisdiction_id');
            $table->bigInteger('population')->default(0);   // own row at compute time — the freshness key
            $table->integer('head_seats')->nullable();
            $table->integer('scope_count')->default(0);
            $table->text('gate_reason')->nullable();         // NULL = gate passed; text = refused with this fact
            $table->string('status', 16)->default('pending');
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('computed_at')->nullable();
            $table->timestampsTz();
            $table->index('status');
        });

        Schema::create('apportionment_ledger_scopes', function (Blueprint $table) {
            $table->uuid('legislature_id');
            $table->uuid('scope_jurisdiction_id');
            $table->uuid('parent_jurisdiction_id')->nullable();
            $table->smallInteger('depth')->default(0);
            $table->integer('walk_position');
            $table->integer('seat_budget');
            $table->primary(['legislature_id', 'scope_jurisdiction_id']);
            $table->index(['legislature_id', 'walk_position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apportionment_ledger_scopes');
        Schema::dropIfExists('apportionment_ledger');
    }
};
