<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A4 (operator ruling 2026-08-29): the '+ children' subtree boot as a
 * multi-lane pile. One row per jurisdiction in the walk; lanes claim from
 * the shallowest unfinished depth only (a parent always boots before its
 * children), each claim is one bounded unit in a fresh-slate job, and a
 * kill costs one node. Elections fire only where voters exist — the boot
 * is structure (seats, board, posture), per the standing mode ruling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtree_boot_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('root_id')->index();
            $table->uuid('jurisdiction_id');
            $table->string('slug', 255);
            $table->integer('depth');
            $table->string('status', 16)->default('pending'); // pending|running|done|review
            $table->uuid('claim_token')->nullable();
            $table->text('reason')->nullable();
            $table->integer('attempts')->default(0);
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['root_id', 'status', 'depth']);
            $table->unique(['root_id', 'jurisdiction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtree_boot_items');
    }
};
