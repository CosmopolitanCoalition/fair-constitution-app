<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W-0432 — account-saved video player preferences. One document per signed-in
 * viewer: the chosen audio and caption language codes, the audio/caption link
 * flag, the captions-on flag, the dub volume and the mute flag. The player
 * seeds from this at mount (server wins over localStorage) and PUTs on change.
 *
 * Additive, real-dated after the latest existing 2026_09_13_190000_* file. The
 * table is new and empty, so a normal transactional migration builds it in one
 * step. The store is NOT audit-chained: preferences are private convenience
 * state, never a constitutional record. Demo-mode sessions write here like any
 * other viewer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_media_prefs')) {
            return;
        }

        Schema::create('user_media_prefs', function (Blueprint $t) {
            $t->uuid('user_id')->primary();
            $t->jsonb('prefs');
            $t->timestamps();

            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_media_prefs');
    }
};
