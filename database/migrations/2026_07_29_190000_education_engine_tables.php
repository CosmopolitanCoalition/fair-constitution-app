<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE K-2 EDUCATION ENGINE SCHEMA (Wave 3, lane 15 — operator ruling A5,
 * K2_ENGINE_PLAN §4; slot-granted after lane 1's 2026_07_29_150000).
 *
 * The graded half of civic education. Content lives in three tables
 * (tracks → modules → questions) seeded from the server-side catalog;
 * progress is the learner's node-local resume state. The training GATE
 * reads NONE of these — it reads only accepted F-EDU-001 chain entries
 * (§5.2 READING RULE: never the achievement ledger, never
 * education_progress), so a role-holder trained on another node is
 * actionable here under Full Faith & Credit.
 *
 * Two rules carried on the shape itself:
 *
 *   - `education_questions.correct_keys` is SERVER-ONLY — never selected
 *     into any Inertia prop, API response, serializer, or form payload.
 *     Enforced by EducationAnswerKeySecrecyTest (source-scan + the
 *     engine's SENSITIVE_KEYS), not by discipline.
 *   - `education_progress` mirrors journey_progress's federation posture
 *     EXACTLY: no source_server_id, no deleted_at, unique on
 *     (user_id, module_id), FK to users ON DELETE CASCADE. It NEVER
 *     federates — the filed F-EDU-001 is what travels.
 *
 * Plus the amendable knob the stipend hook reads (§5.0.1):
 * `training_stipend_amount`, nullable per the monetary-levers house
 * pattern (SettingsResolver takes the first non-null walking up the
 * jurisdiction chain; a NOT NULL DEFAULT column can never inherit).
 *
 * Additive-only, REAL-dated after every prior migration. down() drops
 * cleanly for the cheap-moment proof.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Tracks — one per role's curriculum unit ─────────────────────────
        Schema::create('education_tracks', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'));
            $table->string('key', 64);
            $table->string('title', 160);
            $table->string('unit_ref', 64)->nullable(); // K2_CURRICULUM unit provenance
            $table->string('status', 16)->default('live'); // live | draft | retired
            $table->unsignedSmallInteger('ordering')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->primary('id');
        });
        DB::statement('ALTER TABLE education_tracks ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('
            CREATE UNIQUE INDEX education_tracks_key_unique
                ON education_tracks ("key")
             WHERE deleted_at IS NULL
        ');

        // ── Modules — the lesson unit; the act-gate's redirect target ───────
        // NO role gate of any kind (§5.0.2: every training is open to every
        // user — there is no required_role and no eligibility column, so the
        // "may this person take this training" question is unaskable).
        Schema::create('education_modules', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'));
            $table->uuid('track_id');
            $table->string('key', 64);
            $table->string('title', 160);
            $table->string('surface_id', 64)->nullable(); // joins config/cga/surfaces.php — "that screen's Learn content"
            $table->unsignedSmallInteger('minutes')->nullable(); // weight input: max(minutes/5, 3)
            $table->string('status', 16)->default('live');
            $table->unsignedSmallInteger('ordering')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->primary('id');
            $table->foreign('track_id')->references('id')->on('education_tracks')->cascadeOnDelete();
            $table->index('track_id');
            $table->index('surface_id');
        });
        DB::statement('ALTER TABLE education_modules ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('
            CREATE UNIQUE INDEX education_modules_track_key_unique
                ON education_modules (track_id, "key")
             WHERE deleted_at IS NULL
        ');

        // ── Questions — choices are the display half; correct_keys is the
        //    SERVER-ONLY half (see the file docblock — pinned, not promised).
        Schema::create('education_questions', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'));
            $table->uuid('module_id');
            $table->string('key', 64);
            $table->text('prompt');
            $table->jsonb('choices')->default('[]');
            $table->jsonb('correct_keys'); // SERVER ONLY — never crosses to a client
            $table->unsignedSmallInteger('weight')->default(3);
            $table->unsignedSmallInteger('ordering')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->primary('id');
            $table->foreign('module_id')->references('id')->on('education_modules')->cascadeOnDelete();
            $table->index('module_id');
        });
        DB::statement('ALTER TABLE education_questions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('
            CREATE UNIQUE INDEX education_questions_module_key_unique
                ON education_questions (module_id, "key")
             WHERE deleted_at IS NULL
        ');

        // ── Progress — node-local resume state, NEVER the gate's source ─────
        // Mirrors journey_progress: no source_server_id, no deleted_at.
        // Wrong answers are NOT persisted (§3.5: a per-person error history
        // is a composite score waiting to be computed) — state + latest score
        // + the one-way completion latch, nothing else.
        Schema::create('education_progress', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'));
            $table->uuid('user_id');
            $table->uuid('module_id');
            $table->string('state', 16)->default('in_progress'); // in_progress | completed
            $table->unsignedSmallInteger('score_pct')->nullable();
            $table->timestampTz('completed_at', 0)->nullable();

            $table->timestamps();

            $table->primary('id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('module_id')->references('id')->on('education_modules')->cascadeOnDelete();
            $table->unique(['user_id', 'module_id']);
            $table->index('module_id');
        });
        DB::statement('ALTER TABLE education_progress ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // ── The one-time training stipend's amendable knob (§5.0.1) ─────────
        // Nullable per the monetary-levers pattern; default at the call site
        // (SettingsResolver::resolveInt). From here it moves ONLY through
        // F-LEG-031 → EnactmentService, never an admin knob.
        Schema::table('constitutional_settings', function (Blueprint $table) {
            $table->integer('training_stipend_amount')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('constitutional_settings', function (Blueprint $table) {
            $table->dropColumn('training_stipend_amount');
        });
        Schema::dropIfExists('education_progress');
        Schema::dropIfExists('education_questions');
        Schema::dropIfExists('education_modules');
        Schema::dropIfExists('education_tracks');
    }
};
