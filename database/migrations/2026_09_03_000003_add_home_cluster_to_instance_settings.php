<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G·co-member) — the cluster this instance belongs to (its co-member
 * home). NULL = a standalone single-node instance (its own cluster of one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->uuid('home_cluster_id')->nullable()
                ->comment('Phase G G·co-member: the cluster this instance is a co-member of');
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('home_cluster_id');
        });
    }
};
