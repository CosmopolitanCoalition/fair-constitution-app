<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-CASES E-6 (PHASE_E_DESIGN_cases_juries §A) — `opinions` +
 * `opinion_law_links` (F-JDG-003): the panel's commentary on the law (NOT a
 * change to it). An opinion link is COMMENTARY ONLY — it never mutates
 * laws/law_versions. Editing a law's text is the Art. IV §5 process
 * (F-JDG-006, the sibling design), deliberately a separate table: an opinion
 * can interpret a law without editing it. `public_records.kind='opinion'` is
 * already reserved for opinion publication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opinions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('case_id');
            $table->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();

            $table->uuid('panel_id');
            $table->foreign('panel_id')->references('id')->on('panels')->restrictOnDelete();

            $table->uuid('authored_by_seat_id');
            $table->foreign('authored_by_seat_id')->references('id')->on('judicial_seats')->restrictOnDelete();

            // Multiple opinions per case allowed — no UNIQUE (case_id).
            $table->string('kind', 12);

            $table->string('title');
            $table->text('body');

            $table->uuid('record_id')->nullable();
            $table->timestampTz('published_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['case_id', 'kind']);
        });

        DB::statement('ALTER TABLE opinions ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE opinions ADD CONSTRAINT opinions_kind_check '.
            "CHECK (kind IN ('majority', 'concurrence', 'dissent'))"
        );

        Schema::create('opinion_law_links', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('opinion_id');
            $table->foreign('opinion_id')->references('id')->on('opinions')->cascadeOnDelete();

            $table->uuid('law_id');
            $table->foreign('law_id')->references('id')->on('laws')->restrictOnDelete();

            // Pins the opinion to the law's text AS IT STOOD.
            $table->smallInteger('law_version_no')->nullable();

            $table->string('relation', 12);
            $table->text('note')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE opinion_law_links ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE opinion_law_links ADD CONSTRAINT opinion_law_links_relation_check '.
            "CHECK (relation IN ('cites', 'interprets', 'distinguishes', 'applies'))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX opinion_law_links_unique ON opinion_law_links (opinion_id, law_id, law_version_no) '.
            'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('opinion_law_links');
        Schema::dropIfExists('opinions');
    }
};
