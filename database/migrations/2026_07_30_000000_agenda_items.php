<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * agenda_items — the per-item agenda (Wave 5 ⑤; the deferred keystone debt;
 * migration slot granted by the desk 2026-07-30). ONE additive table that
 * promotes the agenda JSONB blob (on committee_meetings + legislature_sessions)
 * into durable per-item rows: each item carries its own order, kind, optional
 * governance-object link, locked flag, and disposition — so a hearing/session
 * agenda is a real ordered list of dispositions the room walks, not a flat list.
 *
 * POLYMORPHIC HOST (agendable_type/agendable_id) over the two existing agenda
 * hosts: 'committee_meetings' (the keystone hearing) and 'legislature_sessions'.
 * The .agenda JSONB stays the input surface; these rows are the per-item state
 * the room reads and advance() walks (pending → in_progress → done). Soft link
 * to a bill/motion via ref_type/ref_id (no FK — mirrors public_records).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agenda_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('agendable_type', 32);   // committee_meetings | legislature_sessions
            $table->uuid('agendable_id');
            $table->smallInteger('position');        // 1-based order within the agenda
            $table->string('kind', 32)->default('general');
            $table->text('title');
            $table->string('ref_type', 32)->nullable(); // bill | motion | ... (soft ref, no FK)
            $table->uuid('ref_id')->nullable();
            $table->boolean('locked')->default(false);  // engine-locked head (emergency / constitutional)
            $table->string('status', 16)->default('pending');
            $table->text('disposition')->nullable();
            $table->timestamp('taken_up_at')->nullable();
            $table->timestamp('disposed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agendable_type', 'agendable_id', 'position'], 'agenda_items_host_position_idx');
        });

        DB::statement(
            "ALTER TABLE agenda_items ADD CONSTRAINT agenda_items_status_check "
            ."CHECK (status IN ('pending','in_progress','done','deferred'))"
        );
        DB::statement(
            "ALTER TABLE agenda_items ADD CONSTRAINT agenda_items_kind_check "
            ."CHECK (kind IN ('general','bill_floor','motion','committee_report','statement','emergency_power','constitutional_matter'))"
        );

        // One LIVE item per position on a host (the ordered agenda has no dupes);
        // soft-deleted rows drop out so a re-set agenda can reuse positions.
        DB::statement(
            "CREATE UNIQUE INDEX agenda_items_host_position_uq ON agenda_items "
            ."(agendable_type, agendable_id, position) WHERE deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_items');
    }
};
