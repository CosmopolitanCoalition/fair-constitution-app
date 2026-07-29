<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE TYPE B DISTRICT GROUPING SCHEMA (Wave 3, lane 1 — operator rulings
 * B1–B7, brief docs/plans/scaling/TYPE_B_DISTRICT_MAPPER_DESIGN.md).
 *
 * Stage two of the Type B ladder. When even 2-per-constituent still overflows
 * Type A (`legislatures.type_b_needs_districting`), whole sibling constituents
 * are clumped into shared representative PANELS — evenly (equal representation
 * preserved) and compactly (nearest-neighbour, adjacency-weighted). NO geometry
 * is ever cut: this is a balanced grouping over the constituent adjacency graph,
 * not a drawing operation. Populations are consulted at NO step (B2 residual
 * excepted).
 *
 * Mirrors the district trio one-for-one so versioning behaves identically:
 *   legislature_district_maps          → legislature_type_b_groupings   (the versioned plan)
 *   legislature_districts              → legislature_type_b_panels      (the seat-allocation unit)
 *   legislature_district_jurisdictions → legislature_type_b_panel_jurisdictions (membership)
 *
 * B1 — ONE at-large race; panels are the seat-allocation unit inside it:
 *      seats_total = panel_count × rep_floor.
 * B7 — a grouping is VERSIONED (draft/active/archived): a geodata change drafts
 *      a fresh grouping while sitting members serve out; it seats next term. The
 *      `signature` is the deterministic fingerprint the recompute trigger diffs.
 *
 * Additive-only, REAL-dated (2026-07-29, after the flattened baseline and every
 * prior real-dated migration). down() drops cleanly for the cheap-moment proof.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The versioned plan (mirrors legislature_district_maps) ──────────
        Schema::create('legislature_type_b_groupings', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'));
            $table->uuid('legislature_id');
            $table->string('status', 20)->default('draft'); // draft | active | archived

            // What the grouping settled on. rep_floor is the ladder's per-panel
            // seat count; group_size is the base number of constituents per
            // panel; panel_count × rep_floor = seats_total (B1), bounded by
            // type_a_bound (the Type A total this grouping was fit under).
            $table->unsignedSmallInteger('rep_floor');
            $table->unsignedSmallInteger('group_size');
            $table->unsignedSmallInteger('panel_count');
            $table->unsignedSmallInteger('seats_total');
            $table->unsignedSmallInteger('type_a_bound');

            // Provenance + B7 change detection. tie_break_key records which
            // meaningful tie-break decided ties (B5 = 'max_internal_border_len');
            // signature is the deterministic fingerprint of the membership the
            // recompute trigger diffs to detect a geodata change.
            $table->string('tie_break_key', 40)->nullable();
            $table->string('signature', 64)->nullable();

            $table->date('effective_start')->nullable();
            $table->date('effective_end')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->primary('id');
            $table->foreign('legislature_id')->references('id')->on('legislatures')->cascadeOnDelete();
            $table->index('legislature_id');
            $table->index(['legislature_id', 'status']);
        });
        DB::statement('ALTER TABLE legislature_type_b_groupings ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // At most ONE active grouping per legislature — the single lawful plan
        // the R-A guard / race creation reads (B7 lets a draft coexist).
        DB::statement("
            CREATE UNIQUE INDEX legislature_type_b_groupings_active_unique
                ON legislature_type_b_groupings (legislature_id)
             WHERE status = 'active' AND deleted_at IS NULL
        ");

        // ── The panels (mirrors legislature_districts) ──────────────────────
        Schema::create('legislature_type_b_panels', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'));
            $table->uuid('grouping_id');
            $table->uuid('legislature_id'); // denormalized, as districts carry it
            $table->unsignedSmallInteger('panel_number');
            $table->unsignedSmallInteger('seats');        // = rep_floor
            $table->unsignedSmallInteger('member_count'); // constituents on this panel

            $table->timestamps();
            $table->softDeletes();

            $table->primary('id');
            $table->foreign('grouping_id')->references('id')->on('legislature_type_b_groupings')->cascadeOnDelete();
            $table->foreign('legislature_id')->references('id')->on('legislatures')->cascadeOnDelete();
            $table->index('grouping_id');
            $table->index('legislature_id');
        });
        DB::statement('ALTER TABLE legislature_type_b_panels ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement('
            CREATE UNIQUE INDEX legislature_type_b_panels_number_unique
                ON legislature_type_b_panels (grouping_id, panel_number)
             WHERE deleted_at IS NULL
        ');

        // ── Membership (mirrors legislature_district_jurisdictions) ─────────
        // grouping_id is denormalized so a constituent can be guaranteed to sit
        // in exactly ONE panel per grouping without a trigger.
        Schema::create('legislature_type_b_panel_jurisdictions', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'));
            $table->uuid('panel_id');
            $table->uuid('grouping_id');
            $table->uuid('jurisdiction_id');

            $table->primary('id');
            $table->foreign('panel_id')->references('id')->on('legislature_type_b_panels')->cascadeOnDelete();
            $table->foreign('grouping_id')->references('id')->on('legislature_type_b_groupings')->cascadeOnDelete();
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->cascadeOnDelete();
            $table->index('panel_id');
            $table->index('jurisdiction_id');
        });
        DB::statement('ALTER TABLE legislature_type_b_panel_jurisdictions ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // A jurisdiction sits on exactly one panel within a grouping.
        DB::statement('
            CREATE UNIQUE INDEX ltbpj_grouping_jurisdiction_unique
                ON legislature_type_b_panel_jurisdictions (grouping_id, jurisdiction_id)
        ');
        DB::statement('
            CREATE UNIQUE INDEX ltbpj_panel_jurisdiction_unique
                ON legislature_type_b_panel_jurisdictions (panel_id, jurisdiction_id)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('legislature_type_b_panel_jurisdictions');
        Schema::dropIfExists('legislature_type_b_panels');
        Schema::dropIfExists('legislature_type_b_groupings');
    }
};
