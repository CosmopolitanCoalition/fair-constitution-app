<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G — cold-sync cursor. A fresh mirror pulls a peer's full public corpus
 * in bounded, resumable, signed PAGES (a page IS a tail) rather than one
 * multi-MB body — the fix for the body-size failure the live two-instance demo
 * hit. One open cold cursor per (peer, direction); it persists progress so a
 * crashed or heartbeat-drained pull resumes exactly where it stopped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_cursors', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('peer_id');
            $table->foreign('peer_id')->references('id')->on('federation_peers')->cascadeOnDelete();

            $table->string('direction', 8)->default('inbound'); // inbound | outbound
            $table->string('mode', 12)->default('cold');        // cold | incremental

            // Progress walks next_from_seq → (the host's head). anchor_seq is an
            // optional frozen ceiling (the host's head at pull start).
            $table->bigInteger('anchor_seq')->nullable();
            $table->bigInteger('from_seq')->default(0);
            $table->bigInteger('next_from_seq')->default(0);

            $table->unsignedInteger('page_size')->default(500);
            $table->unsignedInteger('pages_applied')->default(0);
            $table->unsignedInteger('records_applied')->default(0);

            // The previous page's head_hash — the cross-page continuity anchor.
            $table->char('last_page_hash', 64)->nullable();

            $table->string('status', 12)->default('open'); // open | paused | complete | aborted
            $table->text('abort_reason')->nullable();
            $table->jsonb('detail')->default('{}');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
        });

        DB::statement('ALTER TABLE sync_cursors ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE sync_cursors ADD CONSTRAINT sync_cursors_direction_check CHECK (direction IN ('inbound','outbound'))");
        DB::statement("ALTER TABLE sync_cursors ADD CONSTRAINT sync_cursors_mode_check CHECK (mode IN ('cold','incremental'))");
        DB::statement("ALTER TABLE sync_cursors ADD CONSTRAINT sync_cursors_status_check CHECK (status IN ('open','paused','complete','aborted'))");

        // One OPEN cold cursor per (peer, direction).
        DB::statement(
            "CREATE UNIQUE INDEX sync_cursors_one_open_cold_per_peer "
          ."ON sync_cursors (peer_id, direction) WHERE deleted_at IS NULL AND mode = 'cold' AND status = 'open'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_cursors');
    }
};
