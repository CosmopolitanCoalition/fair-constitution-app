<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * foundation_sync_cursors (seed paginated redesign) — the per-(peer, table) resume
 * watermark for the NEW paginated foundation drain, the geodata-foundation
 * counterpart of sync_cursors (which tracks the audit-tail cold drain).
 *
 * The old seed transport pulled the whole foundation as ONE opaque pg_restore — invisible
 * until commit, non-resumable mid-table, slow. This redesign drains each foundation table
 * (cosmic_addresses → jurisdictions → worldpop_rasters → geoboundary_metadata →
 * constitutional_settings) in bounded KEYSET pages, UPSERTing per page with the cursor
 * advanced inside the same transaction. A crash resumes from next_from_key; a finished
 * table short-circuits. The committed rows_applied / total_rows columns are read live by
 * SyncProgressService for a per-table progress bar — exactly as the cold cursor drives the
 * audit-history bar.
 *
 * key columns are stored as JSON arrays (from_key / next_from_key) so the SAME shape carries
 * a single-uuid PK (jurisdictions) AND a composite PK (geoboundary_metadata.[iso_code, adm_level]).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foundation_sync_cursors', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('peer_id');               // the donor we are draining the foundation FROM
            $table->string('table_name');          // one of GeodataSeedTransportService::FOUNDATION_TABLES

            // Keyset watermark — JSON arrays of the table's key-column values (the resume token).
            $table->json('from_key')->nullable();      // where this page started (null = the table head)
            $table->json('next_from_key')->nullable(); // the last applied row's key (where the next page starts)

            $table->integer('page_size')->default(250);
            $table->integer('pages_applied')->default(0);
            $table->bigInteger('rows_applied')->default(0);
            $table->bigInteger('total_rows')->nullable();   // donor's count(*) snapshot — the bar denominator

            $table->string('status')->default('open');      // open | complete | aborted
            $table->string('abort_reason')->nullable();
            $table->json('detail')->nullable();             // free-form bookkeeping (e.g. dropped-FK/index state)

            $table->timestamps();
            $table->softDeletes();

            $table->index(['peer_id', 'table_name', 'status']);
        });

        DB::statement('ALTER TABLE foundation_sync_cursors ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('foundation_sync_cursors');
    }
};
