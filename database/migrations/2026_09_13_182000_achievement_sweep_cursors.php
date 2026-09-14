<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AC-1: resumable keyset cursor store for `achievements:sweep`.
 *
 * The EARNER_STATE sweep (AchievementStateSweep) reads the fact tables that
 * NAME a seat/membership/confirmation holder (seats, residency, ballots,
 * memberships) and awards the matching ACH-* row to that holder — idempotent
 * by construction (AchievementService::awardState). To satisfy the ETL
 * paradigm (bounded, resumable, visible), the sweep pages each fact table by
 * keyset (id > cursor ORDER BY id LIMIT chunk) and commits per chunk. This
 * table persists the last id seen PER catalogue key so a killed run resumes
 * from where it stopped rather than rescanning from the start.
 *
 * One row per state key. `cursor` is the last keyset id (a uuid string, or a
 * bigint spelled as text) processed for that key; null / absent means "start
 * from the beginning". Additive only; holds no civic data of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('achievement_sweep_cursors')) {
            return;
        }

        Schema::create('achievement_sweep_cursors', function (Blueprint $table) {
            // The ACH-* catalogue key this cursor tracks (e.g. ACH-CIV-005).
            $table->string('key')->primary();
            // Last keyset id processed for this key (uuid or numeric-as-text).
            $table->string('cursor')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievement_sweep_cursors');
    }
};
