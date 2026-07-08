<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Geodata repair plane — additive on top of the flattened baseline
 * (REAL-dated, 2026-07-08).
 *
 *  1. jurisdictions.merged_into_id — set on a soft-deleted member of a
 *     collapsed same-space chain, pointing at the surviving (topmost) row.
 *     Live-row queries add `merged_into_id IS NULL` alongside the existing
 *     `deleted_at IS NULL` so merged husks never resurface as data.
 *
 *  2. geodata_flags — stored detector output (the Jurisdiction Viewer's
 *     repair queue). Open flags are derived artifacts: every scan of a
 *     category hard-deletes that category's open flags and re-detects.
 *     Accepted/resolved flags persist across rescans, keyed by fingerprint.
 *
 *  3. geodata_repairs — the applied-repair ledger. Each row captures the
 *     FULL prior state in `params` so a repair can be reverted, and links
 *     back to the flag it resolved (if any).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jurisdictions', function (Blueprint $table) {
            // Survivor pointer for merge_chain repairs. ON DELETE SET NULL —
            // a hard-deleted survivor must not strand dangling references.
            $table->uuid('merged_into_id')->nullable();
            $table->foreign('merged_into_id')
                ->references('id')->on('jurisdictions')
                ->nullOnDelete();
            $table->index('merged_into_id');
        });

        Schema::create('geodata_flags', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            // dual_coverage | mis_anchored_cluster | same_space_chain |
            // raster_coverage | displaced_geometry | orphaned_rows
            $table->string('category', 48);
            // info | warning | critical
            $table->string('severity', 16);
            // The row the flag anchors on (NULL for group flags, e.g. orphaned_rows).
            $table->foreignUuid('jurisdiction_id')->nullable()
                ->constrained('jurisdictions')->nullOnDelete();
            $table->foreignUuid('related_jurisdiction_id')->nullable()
                ->constrained('jurisdictions')->nullOnDelete();
            $table->string('title', 255);
            $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
            // sha1(category|sorted target slugs|discriminating fact) — the
            // stable identity that lets accepted flags survive rescans.
            $table->string('fingerprint', 64);
            // accept_flag | synthesize_anchor | merge_chain | reparent |
            // recompute_population | prune
            $table->string('suggested_action', 48)->nullable();
            // open | accepted | resolved
            $table->string('status', 16)->default('open');
            $table->jsonb('resolution')->nullable();
            $table->timestampTz('detected_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['status', 'category']);
            $table->index('jurisdiction_id');
            $table->index('fingerprint');
        });

        Schema::create('geodata_repairs', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('flag_id')->nullable()
                ->constrained('geodata_flags')->nullOnDelete();
            // reparent | synthesize_anchor | merge_chain | prune |
            // recompute_population
            $table->string('action', 48);
            $table->string('target_slug', 255);
            $table->string('target_geoboundaries_id', 64)->nullable();
            // Inputs + FULL prior state (what revert() restores).
            $table->jsonb('params')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('result')->nullable();
            $table->uuid('applied_by')->nullable();
            $table->timestampTz('applied_at');
            $table->timestampTz('reverted_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('action');
            $table->index('target_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geodata_repairs');
        Schema::dropIfExists('geodata_flags');

        Schema::table('jurisdictions', function (Blueprint $table) {
            $table->dropForeign(['merged_into_id']);
            $table->dropIndex(['merged_into_id']);
            $table->dropColumn('merged_into_id');
        });
    }
};
