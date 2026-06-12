<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-7 (PHASE_C_DESIGN_votes_laws §A) — the statute book, git-style.
 *
 * `laws` + `law_versions` (append-only by convention; DECISION: complete
 * text per version, never deltas — diffs are computed at render time;
 * `text_hash` pins each version into the audit chain) + `setting_changes`
 * (the F-LEG-031 ledger).
 *
 * `act_number` ("Act {YYYY}-{NN}") is allocated under
 * pg_advisory_xact_lock(hashtext('act_number:'||legislature_id)); unique
 * (legislature_id, act_number).
 *
 * Also closes two FK loops: `bills.enacted_law_id → laws` and the
 * Phase-A-promised `constitutional_settings.last_amended_by_act_id →
 * laws` (column exists FK-less since 2026_01_01_000002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laws', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Scale anchor.
            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            $table->uuid('legislature_id');
            $table->foreign('legislature_id')->references('id')->on('legislatures')->restrictOnDelete();

            $table->string('act_number');
            $table->string('title');

            $table->string('kind', 24);

            // Copied from bill/petition/question at enactment.
            $table->jsonb('scale');

            $table->uuid('scope_judiciary_id')->nullable();
            $table->foreign('scope_judiciary_id')->references('id')->on('judiciaries')->nullOnDelete();

            // 'judicial_remedy' reserved as a VERSION source (Phase E);
            // origin 'judicial_remedy' exists for E-created severable laws.
            $table->string('origin', 20);

            $table->uuid('enacting_bill_id')->nullable();
            $table->foreign('enacting_bill_id')->references('id')->on('bills')->nullOnDelete();

            // referendum_question / petition — no FK (created in C-8;
            // referendum_questions.resulting_law_id carries the real FK back).
            $table->string('origin_ref_type', 32)->nullable();
            $table->uuid('origin_ref_id')->nullable();

            // CLK-19 inputs (Art. II §6 same-term shield — batch-2 wiring).
            $table->boolean('referendum_passed_by_supermajority')->nullable();
            $table->uuid('shield_expires_with_election_id')->nullable();
            $table->foreign('shield_expires_with_election_id')->references('id')->on('elections')->nullOnDelete();

            $table->string('status', 12)->default('in_force');

            $table->smallInteger('current_version_no')->default(1);

            $table->timestampTz('effective_at');
            $table->timestampTz('enacted_at');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['legislature_id', 'act_number']);
            $table->index(['jurisdiction_id', 'status']);
        });

        DB::statement('ALTER TABLE laws ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("
            ALTER TABLE laws ADD CONSTRAINT laws_kind_check
            CHECK (kind IN ('ordinary','setting_change','rules_of_order','ethics_code','charter',
                            'creation_act','referendum_act','constitutional_article'))
        ");
        DB::statement("
            ALTER TABLE laws ADD CONSTRAINT laws_origin_check
            CHECK (origin IN ('bill','referendum','petition_initiative','judicial_remedy','founding'))
        ");
        DB::statement("
            ALTER TABLE laws ADD CONSTRAINT laws_status_check
            CHECK (status IN ('in_force','amended','repealed','superseded','struck'))
        ");

        Schema::create('law_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('law_id');
            $table->foreign('law_id')->references('id')->on('laws')->cascadeOnDelete();

            $table->smallInteger('version_no');

            // Complete text per version — never deltas (design decision).
            $table->text('text');

            // sha256, recorded in the enactment/amendment audit payload.
            $table->char('text_hash', 64);

            $table->string('source', 24);

            // bill / chamber_vote / constitutional_challenge / disintermediation.
            $table->string('source_ref_type', 32)->nullable();
            $table->uuid('source_ref_id')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['law_id', 'version_no']);
        });

        DB::statement('ALTER TABLE law_versions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("
            ALTER TABLE law_versions ADD CONSTRAINT law_versions_source_check
            CHECK (source IN ('enactment','legislative_amendment','judicial_remedy','referendum_modification','merge_incorporation'))
        ");

        Schema::create('setting_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            $table->uuid('legislature_id')->nullable();
            $table->foreign('legislature_id')->references('id')->on('legislatures')->nullOnDelete();

            $table->string('setting_key');
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value');

            $table->uuid('law_id');
            $table->foreign('law_id')->references('id')->on('laws')->restrictOnDelete();

            $table->timestampTz('applied_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['jurisdiction_id', 'setting_key']);
        });

        DB::statement('ALTER TABLE setting_changes ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // ── Close the FK loops ───────────────────────────────────────────────
        DB::statement('
            ALTER TABLE bills ADD CONSTRAINT bills_enacted_law_id_foreign
            FOREIGN KEY (enacted_law_id) REFERENCES laws(id) ON DELETE SET NULL
        ');
        DB::statement('
            ALTER TABLE constitutional_settings ADD CONSTRAINT constitutional_settings_last_amended_by_act_id_foreign
            FOREIGN KEY (last_amended_by_act_id) REFERENCES laws(id) ON DELETE SET NULL
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE constitutional_settings DROP CONSTRAINT IF EXISTS constitutional_settings_last_amended_by_act_id_foreign');
        DB::statement('ALTER TABLE bills DROP CONSTRAINT IF EXISTS bills_enacted_law_id_foreign');
        Schema::dropIfExists('setting_changes');
        Schema::dropIfExists('law_versions');
        Schema::dropIfExists('laws');
    }
};
