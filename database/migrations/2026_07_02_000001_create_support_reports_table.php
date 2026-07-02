<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SUPPORT REPORTS — the /support/report intake (mockups-v3-wiring Phase 1).
 * A player (or, later, other surfaces) files a bug / question / conduct /
 * legal / appeal report; the row routes the request to the right machinery.
 *
 * Posture:
 *   - LOCAL operational data — NOT mesh-replicated, NO append-only trigger
 *     (this is an inbox, not a constitutional record);
 *   - `public_id` is the shareable short reference ("Report filed —
 *     reference {public_id}") — the pretty-URL foundation, never the uuid;
 *   - conduct/legal categories only ROUTE a request — content removal stays
 *     on the judicial F-SOC-003 carve-out path, never here;
 *   - `reporter_id` is a soft reference (kept if the user row is removed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The public short reference (base62) — what the reporter is told.
            $table->string('public_id', 32)->unique();

            // bug | question | conduct | legal | appeal | other — app-layer validated.
            $table->string('category', 32);

            $table->text('body');

            // The page/URL the report was filed from (the ?ref= param).
            $table->string('ref', 300)->nullable();

            $table->uuid('reporter_id')->nullable();

            // open | triaged | closed — app-layer validated.
            $table->string('status', 32)->default('open');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('category');
            $table->index('status');
            $table->index('reporter_id');
        });

        DB::statement('ALTER TABLE support_reports ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // The reporter is a soft reference — keep the report if the user row is removed.
        DB::statement(
            'ALTER TABLE support_reports ADD CONSTRAINT support_reports_reporter_id_foreign '
          .'FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('support_reports');
    }
};
