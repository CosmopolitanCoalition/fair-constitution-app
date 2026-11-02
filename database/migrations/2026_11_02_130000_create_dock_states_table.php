<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One small document per user for the floating messenger: which conversations are open, which one
 * is active, the public rooms they follow, and where they stopped reading in each. It lets a closed
 * window or a second device come back to the same place. Message text is never stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dock_states', function (Blueprint $t) {
            $t->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $t->jsonb('state');
            $t->timestampTz('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dock_states');
    }
};
