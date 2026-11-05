<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** W-0521 - per-person choices made on the My node screens, kept on the node. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_choices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->string('key', 40);
            $t->jsonb('value');
            $t->timestampsTz();
            $t->unique(['user_id', 'key']);
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_choices');
    }
};
