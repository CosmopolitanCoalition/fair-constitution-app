<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LE-2 — Educational material management and persistent publication
 * (operator ruling rubric lesson-publication-shape = A: structural publish).
 *
 * F-EDU-002 now writes the education_modules row through the single owner
 * EducationCatalogService (title, surface, status). Three additive columns
 * make a publish distinguishable from a revise on the row itself:
 *
 *   - revision_number  1 on first publish, +1 on each revise
 *   - published_by     the R-23 editor's user id (uuid, nullable — seeded
 *                      rows and console publishes carry no editor)
 *   - published_at     the publication stamp
 *
 * Lesson PROSE stays in the K-2 source and the generated registry; there is
 * NO prose column here (K2 SOURCE rule). Additive-only, real-dated after the
 * flattened baseline. Guarded with hasColumn so a re-run is a no-op.
 * Postgres and SQLite safe: plain column adds, no raw DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('education_modules', function (Blueprint $table) {
            if (! Schema::hasColumn('education_modules', 'revision_number')) {
                $table->integer('revision_number')->default(1);
            }
            if (! Schema::hasColumn('education_modules', 'published_by')) {
                $table->uuid('published_by')->nullable();
            }
            if (! Schema::hasColumn('education_modules', 'published_at')) {
                $table->timestampTz('published_at', 0)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('education_modules', function (Blueprint $table) {
            foreach (['revision_number', 'published_by', 'published_at'] as $column) {
                if (Schema::hasColumn('education_modules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
